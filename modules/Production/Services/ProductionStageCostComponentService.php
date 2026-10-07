<?php

namespace Modules\Production\Services;

use App\Services\PostingAccountResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\OverheadAllocationRule;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Core\Models\Currency;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Services\InventoryAccountingPostingService;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionRun;

final class ProductionStageCostComponentService
{
    public const Components = ['material', 'other_direct', 'direct_labor', 'overhead'];

    /** @return array<string, string> */
    public function zero(): array
    {
        return array_fill_keys(self::Components, '0.00000000');
    }

    /** @param array<string, mixed> $values @return array<string, string> */
    public function normalize(array $values): array
    {
        if (array_keys($values) !== self::Components) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        $result = $this->zero();
        foreach (self::Components as $component) {
            if (! is_numeric($values[$component]) || bccomp((string) $values[$component], '0', 8) < 0) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            $result[$component] = bcadd((string) $values[$component], '0', 8);
        }

        return $result;
    }

    /** @param array<string, string> $values */
    public function sum(array $values): string
    {
        return array_reduce($this->normalize($values), fn (string $sum, string $value): string => bcadd($sum, $value, 8), '0.00000000');
    }

    /** @param array<string, string> $left @param array<string, string> $right @return array<string, string> */
    public function add(array $left, array $right): array
    {
        $left = $this->normalize($left);
        $right = $this->normalize($right);
        foreach (self::Components as $component) {
            $left[$component] = bcadd($left[$component], $right[$component], 8);
        }

        return $left;
    }

    /** @param array<string, string> $left @param array<string, string> $right @return array<string, string> */
    public function subtract(array $left, array $right): array
    {
        $left = $this->normalize($left);
        $right = $this->normalize($right);
        foreach (self::Components as $component) {
            $left[$component] = bcsub($left[$component], $right[$component], 8);
        }

        return $this->normalize($left);
    }

    /** @param array<string, string> $pool @return array<string, string> */
    public function share(array $pool, string $quantity, string $whole, ?string $total = null): array
    {
        $pool = $this->normalize($pool);
        if (bccomp($whole, '0', 8) <= 0 || bccomp($quantity, '0', 8) < 0 || bccomp($quantity, $whole, 8) > 0) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        if (bccomp($quantity, $whole, 8) === 0) {
            if ($total !== null && bccomp($total, $this->sum($pool), 8) !== 0) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }

            return $pool;
        }
        $result = $this->zero();
        $remaining = $total ?? bcdiv(bcmul($this->sum($pool), $quantity, 16), $whole, 8);
        $last = collect($pool)->filter(fn (string $value): bool => bccomp($value, '0', 8) > 0)->keys()->last();
        foreach (self::Components as $component) {
            $result[$component] = $component === $last ? $remaining : bcdiv(bcmul($pool[$component], $quantity, 16), $whole, 8);
            $remaining = bcsub($remaining, $result[$component], 8);
        }
        if (bccomp($remaining, '0', 8) !== 0) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }

        return $this->normalize($result);
    }

    /** @return array{components: array<string, string>, accounts: list<int>, expenses: list<array<string, mixed>>, allocations: list<array<string, mixed>>} */
    public function nativeSources(ProductionRun $run): array
    {
        $components = $this->zero();
        $accounts = [];
        $expenses = [];
        foreach (ProductionExpenseRequest::query()->where('company_id', $run->company_id)->where('production_run_id', $run->id)
            ->where('status', ProductionExpenseRequest::StatusPaid)->whereNull('reversal_journal_entry_id')->orderBy('id')->get() as $expense) {
            $snapshot = $expense->cost_accounting_snapshot;
            $currency = Currency::query()->where('company_id', $run->company_id)->findOrFail($expense->currency_id);
            $rate = $currency->is_main ? '1.00000000' : (string) $expense->exchange_rate;
            $base = bcmul((string) $expense->amount, $rate, 8);
            if (! is_array($snapshot) || ($snapshot['version'] ?? null) !== 1 || ($snapshot['capitalized'] ?? null) !== true
                || (int) $expense->branch_id !== (int) $run->branch_id
                || (int) ($snapshot['journal_id'] ?? 0) !== (int) $expense->journal_entry_id
                || (int) ($snapshot['currency_id'] ?? 0) !== (int) $currency->id
                || bccomp($rate, '0', 8) <= 0 || bccomp((string) ($snapshot['base_amount'] ?? '-1'), $base, 8) !== 0
                || bccomp((string) ($snapshot['amount'] ?? '-1'), (string) $expense->amount, 4) !== 0
                || bccomp((string) ($snapshot['exchange_rate'] ?? '0'), $rate, 8) !== 0) {
                throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
            }
            $journal = JournalEntry::query()->with('lines')->findOrFail($expense->journal_entry_id);
            app(ProductionCorrectionContextService::class)->assertOwnedPeriod($run, (int) $expense->financial_period_id);
            $this->assertJournal($journal, $run, 'production_expense_payment', (int) $expense->id, (int) $currency->id, $rate, (int) $expense->financial_period_id);
            $lines = $journal->lines->sortBy('line_no')->values();
            if ($lines->count() !== 2 || (int) $lines[0]->account_id !== (int) $snapshot['debit_account_id']
                || (int) $lines[1]->account_id !== (int) $snapshot['credit_account_id']
                || $lines->contains(fn ($line): bool => $line->cost_center_id !== $run->cost_center_id)
                || bccomp((string) $lines[0]->debit_amount, (string) $expense->amount, 4) !== 0 || bccomp((string) $lines[0]->credit_amount, '0', 4) !== 0
                || bccomp((string) $lines[1]->credit_amount, (string) $expense->amount, 4) !== 0 || bccomp((string) $lines[1]->debit_amount, '0', 4) !== 0) {
                throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
            }
            $this->assertWipAccount((int) $snapshot['debit_account_id'], $run);
            $components['other_direct'] = bcadd($components['other_direct'], $base, 8);
            $accounts[] = (int) $snapshot['debit_account_id'];
            $expenses[] = ['owner' => $expense->getRawOriginal(), 'journal' => $journal->getRawOriginal(), 'lines' => $lines->map->getRawOriginal()->all()];
        }
        $allocations = [];
        foreach (OverheadAllocationRun::query()->where('company_id', $run->company_id)->where('status', OverheadAllocationRun::StatusPosted)
            ->whereHas('lines', fn ($query) => $query->where('production_run_id', $run->id)->where('allocated_amount', '>', 0))->with(['lines', 'sources.journalEntryLine.journalEntry', 'journalEntry.lines'])->orderBy('id')->get() as $allocation) {
            if ((int) $allocation->branch_id !== (int) $run->branch_id
                || $allocation->reversal_journal_entry_id !== null || $allocation->journalEntry === null) {
                throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
            }
            $journal = $allocation->journalEntry;
            app(ProductionCorrectionContextService::class)->assertOwnedPeriod($run, (int) $allocation->financial_period_id);
            $this->assertJournal($journal, $run, 'overhead_allocation', (int) $allocation->id,
                (int) Currency::query()->where('company_id', $run->company_id)->where('is_main', true)->sole()->id, '1', (int) $allocation->financial_period_id);
            $targets = $allocation->lines->sortBy('id')->filter(fn ($line): bool => bccomp((string) $line->allocated_amount, '0', 4) > 0)->values();
            $actual = $journal->lines->sortBy('line_no')->values();
            $sourceAccounts = $allocation->sources->groupBy('account_id')->map(fn ($rows): string => $rows->reduce(
                fn (string $sum, $row): string => bcadd($sum, (string) $row->source_amount, 4), '0.0000'))->filter(fn (string $amount): bool => bccomp($amount, '0', 4) !== 0);
            if (bccomp((string) $allocation->eligible_cost, '0', 4) <= 0
                || bccomp((string) $allocation->eligible_cost, $sourceAccounts->reduce(fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000'), 4) !== 0
                || bccomp((string) $allocation->allocated_cost, (string) $allocation->eligible_cost, 4) > 0
                || $actual->contains(fn ($line): bool => $line->customer_id !== null || $line->supplier_id !== null || $line->employee_id !== null || $line->bank_account_id !== null)
                || $actual->count() !== $targets->count() + $sourceAccounts->count()
                || bccomp((string) $allocation->allocated_cost, $targets->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->allocated_amount, 4), '0.0000'), 4) !== 0) {
                throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
            }
            foreach ($targets as $index => $line) {
                if ($actual[$index]->cost_center_id !== $line->cost_center_id || bccomp((string) $actual[$index]->debit_amount, (string) $line->allocated_amount, 4) !== 0
                    || bccomp((string) $actual[$index]->credit_amount, '0', 4) !== 0) {
                    throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
                }
                if ((int) $line->production_run_id === (int) $run->id) {
                    if ($line->cost_center_id !== $run->cost_center_id) {
                        throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
                    }
                    $kind = $allocation->basis_used === OverheadAllocationRule::BasisDirectPayrollHours ? 'direct_labor' : 'overhead';
                    $components[$kind] = bcadd($components[$kind], (string) $line->allocated_amount, 8);
                    $this->assertWipAccount((int) $actual[$index]->account_id, $run);
                    $accounts[] = (int) $actual[$index]->account_id;
                }
            }
            $remaining = (string) $allocation->allocated_cost;
            $ratio = bcdiv((string) $allocation->allocated_cost, (string) $allocation->eligible_cost, 12);
            $creditIndex = $targets->count();
            foreach ($sourceAccounts as $accountId => $amount) {
                $credit = (int) $accountId === (int) $sourceAccounts->keys()->last() ? $remaining
                    : (string) BigDecimal::of(bcmul($amount, $ratio, 12))->toScale(4, RoundingMode::HalfUp);
                $line = $actual[$creditIndex++];
                if ((int) $line->account_id !== (int) $accountId || (int) $line->cost_center_id !== (int) $allocation->policy_snapshot['source_cost_center_id']
                    || bccomp(bcsub((string) $line->credit_amount, (string) $line->debit_amount, 4), $credit, 4) !== 0) {
                    throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
                }
                $remaining = bcsub($remaining, $credit, 4);
            }
            foreach ($allocation->sources as $source) {
                $line = $source->journalEntryLine;
                $origin = $line?->journalEntry;
                if ($origin === null || (int) $origin->company_id !== (int) $run->company_id || $origin->status !== JournalEntry::StatusPosted || ! $origin->is_posted
                    || $origin->reversed_entry_id !== null || JournalEntry::query()->where('reversed_entry_id', $origin->id)->exists()
                    || (int) $origin->financial_period_id !== (int) $allocation->financial_period_id
                    || (int) ($line->branch_id ?? $origin->branch_id) !== (int) $allocation->branch_id
                    || $origin->entry_date->toDateString() < $allocation->policy_snapshot['from_date']
                    || $origin->entry_date->toDateString() > $allocation->policy_snapshot['to_date']
                    || ProductionExpenseRequest::query()->where('journal_entry_id', $origin->id)->whereNotNull('production_run_id')->exists()
                    || (int) $line->cost_center_id !== (int) $allocation->policy_snapshot['source_cost_center_id']
                    || (int) $line->account_id !== (int) $source->account_id
                    || bccomp(bcmul(bcsub((string) $line->debit_amount, (string) $line->credit_amount, 4), (string) $origin->exchange_rate, 8), (string) $source->source_amount, 4) !== 0) {
                    throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
                }
                if ($allocation->basis_used === OverheadAllocationRule::BasisDirectPayrollHours && ($origin->source_type !== 'hr_payroll_run'
                    || ! DB::table('hr_payroll_postings')->where('payroll_run_id', $origin->source_id)->where('journal_entry_id', $origin->id)->where('status', 'posted')->exists()
                    || $line->employee_id === null)) {
                    throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
                }
            }
            if ($allocation->basis_used === OverheadAllocationRule::BasisDirectPayrollHours) {
                $this->assertPayrollShares($allocation);
            }
            $allocations[] = ['owner' => $allocation->getRawOriginal(), 'lines' => $allocation->lines->sortBy('id')->map->getRawOriginal()->values()->all(),
                'sources' => $allocation->sources->sortBy('id')->map(fn ($source): array => ['owner' => $source->getRawOriginal(),
                    'line' => $source->journalEntryLine->getRawOriginal(), 'journal' => $source->journalEntryLine->journalEntry->getRawOriginal()])->values()->all(),
                'journal' => $journal->getRawOriginal(), 'journal_lines' => $actual->map->getRawOriginal()->all()];
        }

        return ['components' => $components, 'accounts' => array_values(array_unique($accounts)), 'expenses' => $expenses, 'allocations' => $allocations];
    }

    private function assertPayrollShares(OverheadAllocationRun $allocation): void
    {
        $details = collect($allocation->policy_snapshot['direct_payroll_allocations'] ?? []);
        $sourceIds = $allocation->sources->pluck('journal_entry_line_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $detailIds = $details->pluck('journal_entry_line_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        if ($sourceIds !== $detailIds || bccomp((string) $allocation->allocated_cost, (string) $allocation->eligible_cost, 4) !== 0) {
            throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
        }
        foreach ($allocation->sources as $source) {
            $shares = $details->where('journal_entry_line_id', $source->journal_entry_line_id)->values();
            $hours = $shares->reduce(fn (string $sum, array $share): string => bcadd($sum, (string) ($share['hours'] ?? '0'), 8), '0.00000000');
            $remaining = (string) $source->source_amount;
            if (bccomp($hours, '0', 8) <= 0 || $shares->pluck('production_run_id')->unique()->count() !== $shares->count()) {
                throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
            }
            foreach ($shares as $index => $share) {
                $expected = $index === $shares->count() - 1 ? $remaining
                    : (string) BigDecimal::of(bcmul((string) $source->source_amount,
                        bcdiv((string) $share['hours'], $hours, 12), 12))->toScale(4, RoundingMode::HalfUp);
                if ((int) ($share['employee_id'] ?? 0) !== (int) $source->journalEntryLine->employee_id
                    || (int) ($share['account_id'] ?? 0) !== (int) $source->account_id
                    || bccomp((string) ($share['source_amount'] ?? '-1'), (string) $source->source_amount, 4) !== 0
                    || bccomp((string) ($share['total_employee_hours'] ?? '0'), $hours, 8) !== 0
                    || bccomp((string) $share['hours'], '0', 8) <= 0
                    || bccomp((string) ($share['allocated_amount'] ?? '-1'), $expected, 4) !== 0
                    || ! $allocation->lines->contains('production_run_id', $share['production_run_id'] ?? null)) {
                    throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
                }
                $remaining = bcsub($remaining, $expected, 4);
            }
        }
        foreach ($allocation->lines as $target) {
            $amount = $details->where('production_run_id', $target->production_run_id)
                ->reduce(fn (string $sum, array $share): string => bcadd($sum, (string) $share['allocated_amount'], 4), '0.0000');
            if (bccomp($amount, (string) $target->allocated_amount, 4) !== 0) {
                throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
            }
        }
    }

    /** @return array<string, string> */
    public function outputPool(ProductionRun $run): array
    {
        $pool = $this->nativeSources($run)['components'];
        $documents = InventoryDocument::query()->where('company_id', $run->company_id)->where('production_run_id', $run->id)
            ->where('document_type', InventoryDocument::TypeMaterialConsumption)->where('status', InventoryDocument::StatusPosted)->orderBy('id')->get();
        foreach ($documents as $document) {
            app(ProductionCorrectionContextService::class)->assertOwnedPeriod($run, (int) $document->financial_period_id);
            if ((int) $document->branch_id !== (int) $run->branch_id) {
                throw new DomainException(__('production_stage_transfer.unvalued'));
            }
            app(InventoryAccountingPostingService::class)->assertManualCorrectionAccounting($document);
            foreach ($document->lines as $line) {
                if ($line->total_cost === null || (int) $line->production_run_id !== (int) $run->id) {
                    throw new DomainException(__('production_stage_transfer.unvalued'));
                }
                $pool['material'] = bcadd($pool['material'], (string) $line->total_cost, 8);
            }
        }

        return $this->add($pool, app(ProductionStageTransferService::class)->position($run)['used_components']);
    }

    /** @return array<string, string> */
    public function transferComponents(ProductionRun $run, string $quantity): array
    {
        $pool = $this->outputPool($run);
        $loss = app(ProductionStageOutputCostService::class)->position($run);
        $pool = $this->subtract($pool, $loss['excluded_components']);
        $stage = app(ProductionStageTransferService::class)->position($run);
        $pool = $this->subtract($pool, $stage['outgoing_components']);
        if (bccomp((string) $run->received_base_quantity, '0', 8) !== 0) {
            throw new DomainException(__('production_stage_transfer.owner_recovery_required'));
        }

        return $this->share($pool, $quantity, bcsub((string) $run->good_base_quantity, $stage['outgoing_quantity'], 8));
    }

    private function assertWipAccount(int $accountId, ProductionRun $run): void
    {
        if (! Account::query()->forCompany((int) $run->company_id)->eligibleForDirectPosting()->whereKey($accountId)
            ->whereHas('classification', fn ($query) => $query->where('code', PostingAccountResolver::WorkInProcessInventory))->exists()) {
            throw new DomainException(__('production_stage_transfer.mixed_accounts'));
        }
    }

    private function assertJournal(JournalEntry $journal, ProductionRun $run, string $type, int $id, int $currencyId, string $rate, ?int $periodId = null): void
    {
        if ($journal->status !== JournalEntry::StatusPosted || ! $journal->is_posted || ! $journal->is_system_generated
            || (int) $journal->company_id !== (int) $run->company_id || (int) $journal->branch_id !== (int) $run->branch_id
            || (int) $journal->financial_period_id !== ($periodId ?? (int) $run->financial_period_id) || $journal->source_type !== $type || (int) $journal->source_id !== $id
            || (int) $journal->currency_id !== $currencyId || bccomp((string) $journal->exchange_rate, $rate, 6) !== 0
            || $journal->reversed_entry_id !== null || JournalEntry::query()->where('reversed_entry_id', $journal->id)->exists()
            || $journal->lines->contains(fn ($line): bool => (int) ($line->branch_id ?? $journal->branch_id) !== (int) $run->branch_id)) {
            throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
        }
    }
}
