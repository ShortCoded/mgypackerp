<?php

namespace Modules\Inventory\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryReceiptCostProposal;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\InventoryValueAdjustmentLine;
use Modules\Maintenance\Models\MaintenanceMaterialRequest;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCancellationOwnerService;
use Modules\Production\Services\ProductionCorrectionContextService;
use Modules\Production\Services\ProductionReceiptCancellationService;
use Modules\Production\Services\ProductionWarehouseReceiptCorrectionService;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerInvoiceCorrectionService;
use Modules\Sales\Services\SalesAccountingService;
use Modules\Sales\Services\SalesReturnCorrectionService;

class InventoryAccountingPostingService
{
    /** @return list<int> */
    public function historicalWipAccountIds(int $companyId): array
    {
        $ids = Account::withTrashed()->forCompany($companyId)->whereHas('classification', fn ($query) => $query
            ->where('code', PostingAccountResolver::WorkInProcessInventory))->pluck('id')->all();
        $jsonId = fn (string $column, string $path): string => DB::getDriverName() === 'pgsql'
            ? "({$column} #>> '{".str_replace('.', ',', $path)."}')::bigint"
            : "cast(json_extract({$column}, '$.{$path}') as integer)";
        foreach ([['debit_account_id', [InventoryDocument::TypeMaterialIssue, InventoryDocument::TypeAdditionalMaterialIssue]],
            ['credit_account_id', [InventoryDocument::TypeProductionReceipt, InventoryDocument::TypeProductionWaste, InventoryDocument::TypeMaterialReturn]]] as [$field, $types]) {
            $frozen = DB::table('inventory_document_lines as line')->join('inventory_documents as document', 'document.id', '=', 'line.inventory_document_id')
                ->where('document.company_id', $companyId)->whereIn('document.document_type', $types)
                ->selectRaw('distinct '.$jsonId('line.product_snapshot', 'inventory_accounting.'.$field).' as account_id')->pluck('account_id');
            array_push($ids, ...$frozen->all());
        }
        $expenseIds = DB::table('production_expense_requests')->where('company_id', $companyId)->whereNotNull('production_run_id')
            ->selectRaw('distinct '.$jsonId('cost_accounting_snapshot', 'debit_account_id').' as account_id')->pluck('account_id');
        $allocationIds = DB::table('journal_entry_lines as line')->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('cost_overhead_allocation_runs as allocation', 'allocation.id', '=', 'entry.source_id')
            ->where('entry.company_id', $companyId)->where('entry.source_type', 'overhead_allocation')->where('entry.status', 'posted')
            ->where('allocation.company_id', $companyId)->where('entry.is_posted', true)->where('line.debit_amount', '>', 0)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('cost_overhead_allocation_sources as source')
                ->whereColumn('source.allocation_run_id', 'allocation.id')->whereColumn('source.account_id', 'line.account_id'))
            ->distinct()->pluck('line.account_id');
        array_push($ids, ...$expenseIds->all(), ...$allocationIds->all());
        if (Schema::hasTable('production_stage_transfers')) {
            foreach (['source_account_id', 'target_account_id'] as $field) {
                $stageIds = DB::table('production_stage_transfers')->where('company_id', $companyId)->whereNotNull('journal_entry_id')
                    ->selectRaw('distinct '.$jsonId('posting_snapshot', $field).' as account_id')->pluck('account_id');
                array_push($ids, ...$stageIds->all());
            }
        }
        if (Schema::hasTable('production_stage_output_cost_owners')) {
            $outputIds = DB::table('production_stage_output_cost_owners')->where('company_id', $companyId)->whereNotNull('journal_entry_id')
                ->selectRaw('distinct '.$jsonId('posting_snapshot', 'source_account_id').' as account_id')->pluck('account_id');
            array_push($ids, ...$outputIds->all());
        }

        return Account::withTrashed()->forCompany($companyId)->whereIn('id', array_values(array_filter($ids)))
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function __construct(
        private readonly PostingAccountResolver $accounts,
        private readonly JournalEntryService $journals,
    ) {}

    public function post(InventoryDocument $document): ?JournalEntry
    {
        if (! $this->ownsAccounting($document)) {
            return null;
        }

        $document->loadMissing(['lines.product', 'journalEntry', 'productionRun']);

        if ($document->journalEntry instanceof JournalEntry) {
            return $document->journalEntry;
        }

        $event = $this->eventLabel($document);

        if ($document->lines->contains(
            fn ($line): bool => $line->unit_cost === null || $line->total_cost === null,
        )) {
            return null;
        }

        $lines = $this->journalLines($document, $event);

        if ($lines === []) {
            return null;
        }

        $journal = $this->journals->createPostedFromSource(
            $this->header($document, 'inventory_document_posting', $event),
            $lines,
        );

        $document->forceFill(['journal_entry_id' => $journal->getKey()])->save();

        if ($document->document_type === InventoryDocument::TypeMaintenanceMaterialIssue) {
            $this->attachMaintenanceIssueJournalLineage($document, $journal);
        }

        return $journal;
    }

    public function reverse(InventoryDocument $document, ?string $postingDate = null): ?JournalEntry
    {
        return DB::transaction(function () use ($document, $postingDate): ?JournalEntry {
            Company::query()->whereKey($document->company_id)->lockForUpdate()->firstOrFail();
            $document = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            return $this->reverseLocked($document, $postingDate);
        });
    }

    public function reverseForSalesReturnCorrection(InventoryDocument $document, SalesReturn $return, int $correctionId): ?JournalEntry
    {
        $proposal = app(SalesReturnCorrectionService::class)->execution($correctionId, 'return', (int) $return->id);
        if ((int) $document->company_id !== (int) $return->company_id || (int) $document->branch_id !== (int) $return->branch_id
            || $document->source_document_type !== $return::class || (int) $document->source_document_id !== (int) $return->id
            || ! in_array($document->document_type, [InventoryDocument::TypeSalesReturnReceipt, InventoryDocument::TypeTransfer], true)
            || $document->journal_entry_id !== null) {
            throw new DomainException(__('sales_return_plan.source_invalid'));
        }

        return $this->reverseLocked($document, $proposal->posting_date->toDateString(), (int) $proposal->posting_financial_period_id);
    }

    public function reverseForProductionCorrection(InventoryDocument $document, ProductionRun $run, int $correctionId): ?JournalEntry
    {
        Gate::authorize('production.runs.correct_approve');
        $proposal = DB::table('production_run_corrections')->where('id', $correctionId)->where('company_id', $run->company_id)
            ->where('production_run_id', $run->id)->where('status', 'applying')->first();
        if (DB::transactionLevel() < 1 || $proposal === null || (int) $proposal->prepared_by === (int) auth()->id()
            || (int) $document->company_id !== (int) $run->company_id || (int) $document->branch_id !== (int) $run->branch_id
            || (int) $document->production_run_id !== (int) $run->id || $document->source_document_type !== $run::class
            || (int) $document->source_document_id !== (int) $run->id
            || ! in_array($document->document_type, [InventoryDocument::TypeProductionReceipt, InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionWaste], true)) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        $period = app(ProductionCorrectionContextService::class)->target($run, $proposal->posting_date,
            $proposal->correction_mode ?? 'original_period', (int) ($proposal->posting_financial_period_id ?? $proposal->financial_period_id));
        app(ProductionReceiptCancellationService::class)->assertSelectedReceipt($proposal, $document);
        $this->assertProductionCorrectionSourceJournal($document);

        return $this->reverseLocked($document, $proposal->posting_date, (int) $period->id);
    }

    public function reverseForProductionWarehouseCorrection(InventoryDocument $document, int $proposalId): ?JournalEntry
    {
        $proposal = app(ProductionWarehouseReceiptCorrectionService::class)->execution($proposalId, (int) $document->id);
        $this->assertManualCorrectionAccounting($document);

        return $this->reverseLocked($document, $proposal->posting_date->toDateString(), (int) $proposal->posting_financial_period_id);
    }

    public function reverseForProductionMaterialCorrection(InventoryDocument $document, int $ownerId): ?JournalEntry
    {
        $owner = app(ProductionCancellationOwnerService::class)->executionForInventoryDocument($ownerId, (int) $document->id);
        $run = ProductionRun::query()->where('company_id', $owner->company_id)->findOrFail($owner->production_run_id);
        $period = app(ProductionCorrectionContextService::class)->ownerPostingPeriod($run, $owner->posting_date);
        if ((int) $period->id !== (int) $owner->posting_financial_period_id) {
            throw new DomainException(__('production_run_correction.target_changed'));
        }
        $this->assertProductionCorrectionSourceJournal($document);

        return $this->reverseLocked($document, $owner->posting_date, (int) $period->id);
    }

    public function reverseForManualCorrection(InventoryDocument $document, int $proposalId): ?JournalEntry
    {
        $proposal = app(InventoryMovementCorrectionService::class)->execution($proposalId, (int) $document->id);
        $this->assertManualCorrectionAccounting($document);

        return $this->reverseLocked($document, $proposal->posting_date->toDateString(), (int) $proposal->posting_financial_period_id);
    }

    public function reverseForInvoiceCorrection(InventoryDocument $document, int $proposalId): ?JournalEntry
    {
        $proposal = app(CustomerInvoiceCorrectionService::class)->execution($proposalId, (int) $document->id);

        $this->assertInvoiceCorrectionCompletionSource($document);
        $journal = $this->reverseLocked($document, $proposal->posting_date->toDateString(), (int) $proposal->posting_financial_period_id);
        $this->assertCorrectionCompletionReversal($document);

        return $journal;
    }

    public function assertInvoiceCorrectionCompletionSource(InventoryDocument $document): void
    {
        if (JournalEntry::withTrashed()->where('company_id', $document->company_id)
            ->where('source_type', 'inventory_document_cost_completion_reversal')->where('source_id', $document->id)->exists()) {
            throw new DomainException(__('invoice_correction.source_invalid'));
        }
        foreach ($this->completedCostCorrections($document)->pluck('adjustment')->unique('id') as $adjustment) {
            $this->adjustmentBookedAmounts($adjustment);
            if ((int) $adjustment->company_id !== (int) $document->company_id || $adjustment->journalEntry?->reversed_entry_id !== null) {
                throw new DomainException(__('invoice_correction.source_invalid'));
            }
        }
    }

    public function assertCorrectionCompletionReversal(InventoryDocument $document): void
    {
        $expected = $this->completedCostReversalLines($this->completedCostCorrections($document), $document);
        $exists = JournalEntry::withTrashed()->where('company_id', $document->company_id)
            ->where('source_type', 'inventory_document_cost_completion_reversal')->where('source_id', $document->id)->exists();
        if (($expected === [] && $exists) || ($expected !== [] && ! $this->completionReversalCoversPrecision($document))) {
            throw new DomainException(__('invoice_correction.source_invalid'));
        }
    }

    public function assertManualCorrectionAccounting(InventoryDocument $document): void
    {
        if ($document->document_type === InventoryDocument::TypeSalesDelivery) {
            app(SalesAccountingService::class)->assertDeliveryCorrectionSource($document);
            $this->assertInvoiceCorrectionCompletionSource($document);

            return;
        }
        $this->assertProductionCorrectionSourceJournal($document);
        $ids = $document->transactions()->where('is_reversal', false)->pluck('id');
        $adjustments = InventoryValueAdjustment::query()->where('company_id', $document->company_id)
            ->where('status', InventoryValueAdjustment::StatusPosted)->whereHas('lines', fn ($query) => $query->whereIn('source_transaction_id', $ids))->get();
        foreach ($adjustments as $adjustment) {
            $this->adjustmentBookedAmounts($adjustment);
            if ($adjustment->journalEntry?->reversed_entry_id !== null) {
                throw new DomainException(__('inventory_correction.lineage'));
            }
        }
        if (JournalEntry::query()->where('company_id', $document->company_id)->where('source_type', 'inventory_document_cost_completion_reversal')
            ->where('source_id', $document->id)->exists()) {
            throw new DomainException(__('inventory_correction.lineage'));
        }
    }

    private function assertProductionCorrectionSourceJournal(InventoryDocument $document): void
    {
        $document->load(['lines.product', 'journalEntry.lines']);
        if ($this->isLegacyUnbookedTransfer($document)) {
            if ($document->reversal_journal_entry_id !== null) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }

            return;
        }
        if (! $this->ownsAccounting($document)) {
            if ($document->journal_entry_id !== null || $document->reversal_journal_entry_id !== null) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }

            return;
        }
        $journal = $document->journalEntry;
        if ($journal === null) {
            if ($document->lines->contains(fn ($line): bool => $line->total_cost !== null && bccomp((string) $line->total_cost, '0', 8) !== 0)) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }

            return;
        }
        $this->assertPostedJournalHeader($journal, (int) $document->company_id, (int) $document->financial_period_id,
            'inventory_document_posting', (int) $document->id, (int) $document->branch_id, $document->document_date->toDateString());
        if ($document->reversal_journal_entry_id !== null || $journal->reversed_entry_id !== null
            || JournalEntry::query()->where('reversed_entry_id', $journal->id)->exists()) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        $expected = $actual = [];
        foreach ($this->sourcePostingPairs($document) as $pair) {
            foreach (['debit', 'credit'] as $side) {
                if (bccomp($pair['booked_amount'], '0', 4) === 0) {
                    continue;
                }
                $key = implode(':', [$pair[$side.'_account_id'], $pair[$side.'_branch_id'] ?? $pair['branch_id'], $pair['cost_center_id'] ?? 'none', $side]);
                $expected[$key] = bcadd($expected[$key] ?? '0', $pair['booked_amount'], 4);
            }
        }
        $accountIds = $journal->lines->pluck('account_id')->unique();
        if (Account::withTrashed()->forCompany((int) $document->company_id)->whereIn('id', $accountIds)->count() !== $accountIds->count()) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        foreach ($journal->lines as $line) {
            if ($line->customer_id !== null || $line->supplier_id !== null || $line->employee_id !== null
                || $line->department_id !== null || $line->bank_account_id !== null) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
            foreach (['debit', 'credit'] as $side) {
                $amount = (string) $line->{$side.'_amount'};
                if (bccomp($amount, '0', 4) < 0) {
                    throw new DomainException(__('production_run_correction.lineage_invalid'));
                }
                if (bccomp($amount, '0', 4) === 0) {
                    continue;
                }
                $key = implode(':', [$line->account_id, $line->branch_id, $line->cost_center_id ?? 'none', $side]);
                $actual[$key] = bcadd($actual[$key] ?? '0', $amount, 4);
            }
        }
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
    }

    private function reverseLocked(InventoryDocument $document, ?string $postingDate, ?int $postingPeriodId = null): ?JournalEntry
    {
        $document->loadMissing(['journalEntry', 'journalEntry.lines']);

        $postingPeriodId ??= (int) $document->financial_period_id;
        $completionReversal = $this->reverseCompletedCost($document, $postingDate ?? $document->document_date->toDateString(), $postingPeriodId);
        if (! $document->journalEntry instanceof JournalEntry) {
            if ($completionReversal) {
                $document->forceFill(['reversal_journal_entry_id' => $completionReversal->id])->save();
            }

            return $completionReversal;
        }

        if ($document->reversal_journal_entry_id !== null) {
            return JournalEntry::query()->find($document->reversal_journal_entry_id);
        }

        $event = __('Reversal of :document', ['document' => $document->doc_num]);
        $journal = $this->journals->createPostedReversalFromSource(
            $document->journalEntry,
            [...$this->header($document, 'inventory_document_reversal', $event), 'entry_date' => $postingDate ?? $document->document_date,
                'financial_period_id' => $postingPeriodId],
        );

        $document->forceFill(['reversal_journal_entry_id' => $journal->getKey()])->save();

        return $journal;
    }

    private function reverseCompletedCost(InventoryDocument $document, string $postingDate, int $postingPeriodId): ?JournalEntry
    {
        $existing = JournalEntry::query()->where('company_id', $document->company_id)
            ->where('source_type', 'inventory_document_cost_completion_reversal')->where('source_id', $document->id)
            ->lockForUpdate()->first();
        if ($existing !== null) {
            return $existing;
        }
        $corrections = $this->completedCostCorrections($document);
        if ($corrections->isEmpty()) {
            return null;
        }
        app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $document->company_id, $postingDate,
            expectedPeriodId: $postingPeriodId, lockForUpdate: true);
        $branchIds = $corrections->pluck('branch_id')->filter()->unique()->values()->all();
        if (app(OperatingContextService::class)->allowedBranchQueryForCurrentCompany(request())
            ->whereIn('branches.id', $branchIds)->count() !== count($branchIds)) {
            throw new AuthorizationException(__('inventory.movements.messages.receipt_completion_branch_access'));
        }
        $lines = $this->completedCostReversalLines($corrections, $document);
        if ($lines === []) {
            return null;
        }

        return $this->journals->createPostedFromSource([...$this->header($document, 'inventory_document_cost_completion_reversal', __('Reversal of :document', ['document' => $document->doc_num])),
            'branch_id' => collect($lines)->pluck('branch_id')->unique()->count() === 1 ? $lines[0]['branch_id'] : null,
            'entry_date' => $postingDate, 'financial_period_id' => $postingPeriodId], $lines);
    }

    /** @return Collection<int, InventoryValueAdjustmentLine> */
    private function completedCostCorrections(InventoryDocument $document): Collection
    {
        $transactionIds = $document->transactions()->where('is_reversal', false)->pluck('id');
        $proposalAdjustmentIds = InventoryValueAdjustment::query()->where('source_type', InventoryReceiptCostProposal::class)
            ->where('status', InventoryValueAdjustment::StatusPosted)->whereIn('source_id',
                InventoryReceiptCostProposal::query()->where('inventory_document_id', $document->id)->select('id'))->pluck('id');

        return InventoryValueAdjustmentLine::query()->with('adjustment.lines')
            ->whereHas('adjustment', fn ($query) => $query->where('status', InventoryValueAdjustment::StatusPosted))
            ->where(fn ($query) => $query->whereIn('source_transaction_id', $transactionIds)->orWhere(fn ($query) => $query
                ->whereIn('inventory_value_adjustment_id', $proposalAdjustmentIds)->where('effect', InventoryValueAdjustmentLine::EffectCounterpart)))
            ->get();
    }

    /** @param Collection<int, InventoryValueAdjustmentLine> $corrections @return list<array<string, mixed>> */
    private function completedCostReversalLines(Collection $corrections, InventoryDocument $document): array
    {
        $grouped = [];
        $booked = [];
        foreach ($corrections as $line) {
            if (! isset($booked[$line->inventory_value_adjustment_id])) {
                $sourceNet = $line->adjustment->lines->reduce(fn (string $sum, InventoryValueAdjustmentLine $source): string => bcadd($sum, (string) $source->amount, 8), '0');
                if (bccomp($sourceNet, '0', 8) !== 0) {
                    throw new DomainException(__('inventory.movements.messages.receipt_completion_unbalanced'));
                }
                $booked[$line->inventory_value_adjustment_id] = $this->adjustmentBookedAmounts($line->adjustment);
            }
            $key = implode(':', [$line->inventory_value_adjustment_id, $line->account_id, $line->branch_id, $line->cost_center_id ?? 'none']);
            $grouped[$key] ??= ['adjustment_id' => $line->inventory_value_adjustment_id, 'account_id' => $line->account_id,
                'branch_id' => $line->branch_id, 'cost_center_id' => $line->cost_center_id, 'amount' => '0'];
            $grouped[$key]['amount'] = bcsub($grouped[$key]['amount'], $booked[$line->inventory_value_adjustment_id][$line->id], 4);
        }
        $lines = [];
        $branchTotals = [];
        foreach ($grouped as $group) {
            $amount = $group['amount'];
            $key = $group['adjustment_id'].':'.$group['branch_id'];
            $branchTotals[$key] ??= ['adjustment_id' => $group['adjustment_id'], 'branch_id' => $group['branch_id'], 'amount' => '0'];
            $branchTotals[$key]['amount'] = bcadd($branchTotals[$key]['amount'], $amount, 4);
            if (bccomp($amount, '0', 4) === 0) {
                continue;
            }
            $lines[] = [...collect($group)->except(['amount', 'adjustment_id'])->all(), 'description' => __('Reversal of :document', ['document' => $document->doc_num]),
                'debit_amount' => bccomp($amount, '0', 4) >= 0 ? $amount : '0.0000',
                'credit_amount' => bccomp($amount, '0', 4) < 0 ? bcmul($amount, '-1', 4) : '0.0000'];
        }
        foreach ($branchTotals as $branch) {
            if (bccomp($branch['amount'], '0', 4) === 0) {
                continue;
            }
            $adjustment = $corrections->firstWhere('inventory_value_adjustment_id', $branch['adjustment_id'])->adjustment;
            $counterpartIds = $adjustment->lines->where('effect', InventoryValueAdjustmentLine::EffectCounterpart)->pluck('account_id')->unique();
            if ($counterpartIds->count() !== 1) {
                throw new DomainException(__('inventory.movements.messages.receipt_completion_unbalanced'));
            }
            $lines[] = ['account_id' => $counterpartIds->sole(), 'branch_id' => $branch['branch_id'],
                'description' => __('Reversal of :document', ['document' => $document->doc_num]).' / '.$adjustment->source_doc_num,
                'debit_amount' => bccomp($branch['amount'], '0', 4) < 0 ? bcmul($branch['amount'], '-1', 4) : '0.0000',
                'credit_amount' => bccomp($branch['amount'], '0', 4) > 0 ? $branch['amount'] : '0.0000'];
        }

        return $lines;
    }

    public function completionReversalCoversPrecision(InventoryDocument $document): bool
    {
        $journal = JournalEntry::query()->with('lines')->where('company_id', $document->company_id)
            ->where('source_type', 'inventory_document_cost_completion_reversal')->where('source_id', $document->id)->lockForUpdate()->first();
        if ($journal === null) {
            return false;
        }
        $transactionIds = $document->transactions()->where('is_reversal', false)->pluck('id');
        $proposalIds = InventoryValueAdjustment::query()->where('source_type', InventoryReceiptCostProposal::class)
            ->where('status', InventoryValueAdjustment::StatusPosted)->whereIn('source_id',
                InventoryReceiptCostProposal::query()->where('inventory_document_id', $document->id)->select('id'))->pluck('id');
        $corrections = InventoryValueAdjustmentLine::query()->with('adjustment.lines')
            ->whereHas('adjustment', fn ($query) => $query->where('status', InventoryValueAdjustment::StatusPosted))
            ->where(fn ($query) => $query->whereIn('source_transaction_id', $transactionIds)->orWhere(fn ($query) => $query
                ->whereIn('inventory_value_adjustment_id', $proposalIds)->where('effect', InventoryValueAdjustmentLine::EffectCounterpart)))->get();
        $expected = $actual = [];
        $lines = $this->completedCostReversalLines($corrections, $document);
        foreach ($lines as $line) {
            $key = implode(':', [$line['account_id'], $line['branch_id'], $line['cost_center_id'] ?? 'none']);
            $expected[$key] = bcadd($expected[$key] ?? '0', bcsub($line['debit_amount'], $line['credit_amount'], 4), 4);
        }
        foreach ($journal->lines as $line) {
            $key = implode(':', [$line->account_id, $line->branch_id, $line->cost_center_id ?? 'none']);
            $actual[$key] = bcadd($actual[$key] ?? '0', bcsub((string) $line->debit_amount, (string) $line->credit_amount, 4), 4);
        }
        $expected = array_filter($expected, fn ($amount): bool => bccomp($amount, '0', 4) !== 0);
        $actual = array_filter($actual, fn ($amount): bool => bccomp($amount, '0', 4) !== 0);
        ksort($expected);
        ksort($actual);
        $reversals = $document->transactions()->where('is_reversal', true)->get();
        $dates = $reversals->pluck('transaction_date')
            ->map(fn ($date): string => $date->toDateString())->unique();
        $periods = $reversals->pluck('financial_period_id')->unique();
        if ($dates->count() !== 1 || $periods->count() !== 1 || $expected !== $actual) {
            throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
        }
        $branches = collect($lines)->pluck('branch_id')->unique();
        $this->assertPostedJournalHeader($journal, (int) $document->company_id, (int) $periods->sole(),
            'inventory_document_cost_completion_reversal', (int) $document->id,
            $branches->count() === 1 ? (int) $branches->sole() : null, $dates->sole());

        return true;
    }

    public function assertPostedJournalHeader(JournalEntry $journal, int $companyId, int $periodId, string $sourceType, int $sourceId, ?int $branchId, string $date, ?int $currencyId = null): void
    {
        $currencyId ??= Currency::query()->forCompany($companyId)->where('is_main', true)->sole()->id;
        if ($journal->status !== JournalEntry::StatusPosted || ! $journal->is_posted
            || (int) $journal->company_id !== $companyId || (int) $journal->financial_period_id !== $periodId
            || $journal->source_type !== $sourceType || (int) $journal->source_id !== $sourceId
            || (int) $journal->currency_id !== (int) $currencyId || bccomp((string) $journal->exchange_rate, '1', 8) !== 0
            || ($journal->branch_id === null ? null : (int) $journal->branch_id) !== $branchId
            || ! $journal->entry_date->isSameDay($date)) {
            throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
        }
    }

    private function ownsAccounting(InventoryDocument $document): bool
    {
        if ($document->document_type === InventoryDocument::TypeTransfer) {
            return $document->source_document_type !== SalesReturn::class
                && $document->destinationBranchStore !== null
                && (int) $document->destinationBranchStore->branch_id !== (int) $document->branch_id;
        }
        if ($document->document_type === InventoryDocument::TypeProductionReceipt
            && $document->production_run_id === null
            && ! $document->lines->contains(fn (InventoryDocumentLine $line): bool => $line->production_run_id !== null)) {
            return false;
        }

        return in_array($document->document_type, [
            InventoryDocument::TypeReceipt,
            InventoryDocument::TypeIssue,
            InventoryDocument::TypeReturn,
            InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue,
            InventoryDocument::TypeMaterialReturn,
            InventoryDocument::TypeProductionWaste,
            InventoryDocument::TypeProductionReceipt,
            InventoryDocument::TypeAdjustmentIn,
            InventoryDocument::TypeAdjustmentOut,
            InventoryDocument::TypeMaintenanceMaterialIssue,
            InventoryDocument::TypeMaintenanceMaterialReturn,
            InventoryDocument::TypeScrap,
        ], true);
    }

    /** @return list<array<string, mixed>> */
    private function journalLines(
        InventoryDocument $document,
        string $event,
    ): array {
        if ($document->document_type === InventoryDocument::TypeMaintenanceMaterialReturn) {
            return $this->maintenanceReturnJournalLines($document, $event);
        }

        $grouped = [];
        foreach ($document->lines as $line) {
            $contract = $this->previewLinePosting($document, $line, $event);
            $costCenterId = $contract['cost_center_id'];
            $amount = $contract['booked_amount'];
            $debitAccountId = $contract['debit_account_id'];
            $creditAccountId = $contract['credit_account_id'];
            $snapshot = $line->product_snapshot ?? [];
            $snapshot['inventory_accounting'] = collect($contract)->only([
                'rounding_rule', 'debit_account_id', 'credit_account_id', 'debit_branch_id', 'credit_branch_id', 'cost_center_id', 'exact_total_cost', 'booked_amount',
            ])->all();
            $line->forceFill(['product_snapshot' => $snapshot])->save();

            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            if ($document->document_type === InventoryDocument::TypeMaintenanceMaterialIssue) {
                $snapshot = $line->product_snapshot ?? [];
                $snapshot['maintenance_accounting'] = [
                    'inventory_account_id' => (int) $creditAccountId,
                    'expense_account_id' => (int) $debitAccountId,
                    'cost_center_id' => $costCenterId === null ? null : (int) $costCenterId,
                ];
                $line->forceFill(['product_snapshot' => $snapshot])->save();
            }

            $this->addGroupedLine($grouped, (int) $debitAccountId, $amount, '0.0000', $event, $costCenterId, $contract['debit_branch_id']);
            $this->addGroupedLine($grouped, (int) $creditAccountId, '0.0000', $amount, $event, $costCenterId, $contract['credit_branch_id']);
        }

        return array_values($grouped);
    }

    /** @return array<string, mixed> */
    public function previewLinePosting(InventoryDocument $document, InventoryDocumentLine $line, ?string $event = null): array
    {
        $event ??= $this->eventLabel($document);
        $usesProductionCostCenter = in_array($document->document_type, [InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue, InventoryDocument::TypeMaterialReturn,
            InventoryDocument::TypeProductionWaste, InventoryDocument::TypeProductionReceipt], true);
        $costCenterId = $usesProductionCostCenter
            ? ($line->productionRun?->cost_center_id ?? $document->productionRun?->cost_center_id)
            : ($this->isMaintenanceMaterialDocument($document) ? $this->maintenanceCostCenterId($document) : null);
        if ($line->total_cost === null) {
            throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
        }
        $inventory = $this->accounts->inventoryForProduct((int) $document->company_id, $line->product, $event);
        [$debit, $credit] = $this->postingAccounts($document, $line, $event, (int) $inventory->id);
        $accounts = Account::query()->whereIn('id', [$debit, $credit])->get()->keyBy('id');
        $booked = bcround((string) $line->total_cost, 4);
        $header = $this->header($document, 'inventory_document_posting', $event);

        return [
            'company_id' => (int) $document->company_id, 'branch_id' => (int) $document->branch_id,
            'financial_period_id' => (int) $document->financial_period_id,
            'currency_id' => $header['currency_id'], 'exchange_rate' => '1.00000000',
            'debit_account_id' => (int) $debit, 'credit_account_id' => (int) $credit,
            'debit_branch_id' => $document->document_type === InventoryDocument::TypeTransfer
                ? (int) $document->destinationBranchStore->branch_id : (int) $document->branch_id,
            'credit_branch_id' => (int) $document->branch_id,
            'cost_center_id' => $costCenterId === null ? null : (int) $costCenterId,
            'debit_account' => $accounts[$debit]->only(['account_code', 'name', 'name_en']),
            'credit_account' => $accounts[$credit]->only(['account_code', 'name', 'name_en']),
            'rounding_rule' => 'half_up_line_gl_4_v1', 'exact_total_cost' => (string) $line->total_cost,
            'booked_amount' => $booked, 'rounding_difference' => bcsub((string) $line->total_cost, $booked, 8),
        ];
    }

    /** @return array{int, int} */
    private function postingAccounts(InventoryDocument $document, InventoryDocumentLine $line, string $event, int $inventoryAccount): array
    {
        return match ($document->document_type) {
            InventoryDocument::TypeTransfer => [$inventoryAccount, $inventoryAccount],
            InventoryDocument::TypeReceipt,
            InventoryDocument::TypeReturn,
            InventoryDocument::TypeAdjustmentIn => [
                $inventoryAccount,
                $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::InventoryAdjustmentGain, $event)->getKey(),
            ],
            InventoryDocument::TypeIssue,
            InventoryDocument::TypeAdjustmentOut => [
                $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::InventoryAdjustmentLoss, $event)->getKey(),
                $inventoryAccount,
            ],
            InventoryDocument::TypeMaintenanceMaterialIssue => [
                $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::FactoryMaintenanceExpense, $event)->getKey(),
                $inventoryAccount,
            ],
            InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue => [
                $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::WorkInProcessInventory, $event)->getKey(),
                $inventoryAccount,
            ],
            InventoryDocument::TypeMaterialReturn => [
                $inventoryAccount,
                $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::WorkInProcessInventory, $event)->getKey(),
            ],
            InventoryDocument::TypeProductionWaste => [
                $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::AbnormalWasteLoss, $event)->getKey(),
                $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::WorkInProcessInventory, $event)->getKey(),
            ],
            InventoryDocument::TypeProductionReceipt => [
                $inventoryAccount,
                $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::WorkInProcessInventory, $event)->getKey(),
            ],
            InventoryDocument::TypeScrap => [
                $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::WarehouseDamageLoss, $event)->getKey(),
                $inventoryAccount,
            ],
            default => throw new DomainException(__('Unsupported inventory accounting event.')),
        };
    }

    /** @return list<array<string, mixed>> */
    public function sourcePostingPairs(InventoryDocument $document): array
    {
        $document->loadMissing(['lines.product', 'lines.productionRun', 'productionRun']);
        if (! $this->ownsAccounting($document) || $this->isLegacyUnbookedTransfer($document)) {
            return [];
        }
        if ($document->document_type === InventoryDocument::TypeMaintenanceMaterialReturn) {
            return $this->maintenanceReturnSourcePairs($document);
        }
        $event = $this->eventLabel($document);
        $production = in_array($document->document_type, [InventoryDocument::TypeMaterialIssue, InventoryDocument::TypeAdditionalMaterialIssue,
            InventoryDocument::TypeMaterialReturn, InventoryDocument::TypeProductionWaste, InventoryDocument::TypeProductionReceipt], true);
        $pairs = [];
        foreach ($document->lines->sortBy('id') as $line) {
            if ($line->total_cost === null || $line->unit_cost === null) {
                throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
            }
            $snapshot = $line->product_snapshot['inventory_accounting'] ?? null;
            $debitBranchId = $document->document_type === InventoryDocument::TypeTransfer
                ? (int) $document->destinationBranchStore->branch_id : (int) $document->branch_id;
            $creditBranchId = (int) $document->branch_id;
            $center = $snapshot['cost_center_id'] ?? ($production ? ($line->productionRun?->cost_center_id ?? $document->productionRun?->cost_center_id)
                : ($this->isMaintenanceMaterialDocument($document) ? $this->maintenanceCostCenterId($document) : null));
            if (is_array($snapshot)) {
                if ($snapshot['rounding_rule'] !== 'half_up_line_gl_4_v1'
                    || bccomp($snapshot['exact_total_cost'], (string) $line->total_cost, 8) !== 0
                    || bccomp($snapshot['booked_amount'], bcround((string) $line->total_cost, 4), 4) !== 0) {
                    throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
                }
                $debit = (int) $snapshot['debit_account_id'];
                $credit = (int) $snapshot['credit_account_id'];
                if ((int) ($snapshot['debit_branch_id'] ?? $debitBranchId) !== $debitBranchId
                    || (int) ($snapshot['credit_branch_id'] ?? $creditBranchId) !== $creditBranchId
                    || ($document->document_type === InventoryDocument::TypeTransfer && $debit !== $credit)) {
                    throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
                }
            } else {
                $maintenance = $line->product_snapshot['maintenance_accounting'] ?? null;
                if (is_array($maintenance)) {
                    $debit = (int) $maintenance['expense_account_id'];
                    $credit = (int) $maintenance['inventory_account_id'];
                    $center = $maintenance['cost_center_id'];
                } else {
                    $inventory = $this->accounts->inventoryForProduct((int) $document->company_id, $line->product, $event);
                    [$debit, $credit] = $this->postingAccounts($document, $line, $event, (int) $inventory->id);
                }
            }
            $pairs[] = ['document_line_id' => (int) $line->id, 'debit_account_id' => $debit, 'credit_account_id' => $credit,
                'debit_branch_id' => $debitBranchId, 'credit_branch_id' => $creditBranchId,
                'branch_id' => (int) $document->branch_id, 'cost_center_id' => $center === null ? null : (int) $center,
                'exact_total_cost' => (string) $line->total_cost,
                'booked_amount' => $snapshot['booked_amount'] ?? bcadd((string) $line->total_cost, '0', 4),
                'canonical_amount' => bcround((string) $line->total_cost, 4),
                'source_rule' => $snapshot['rounding_rule'] ?? 'legacy_truncate_line_gl_4'];
        }

        return $pairs;
    }

    private function isLegacyUnbookedTransfer(InventoryDocument $document): bool
    {
        return $document->document_type === InventoryDocument::TypeTransfer && $document->journal_entry_id === null
            && ! $document->lines->contains(fn (InventoryDocumentLine $line): bool => is_array($line->product_snapshot['inventory_accounting'] ?? null));
    }

    /** @return list<array<string, mixed>> */
    private function maintenanceReturnJournalLines(InventoryDocument $document, string $event): array
    {
        if ($document->source_document_type !== MaintenanceMaterialRequest::class) {
            throw new DomainException(__('Maintenance inventory accounting requires its material request source.'));
        }

        $lines = [];
        foreach ($document->lines as $line) {
            $sourceIssueId = $line->product_snapshot['source_issue_transaction_id'] ?? null;
            $sourceIssue = is_numeric($sourceIssueId)
                ? InventoryTransaction::query()->lockForUpdate()->find((int) $sourceIssueId)
                : null;
            $sourceDocument = $sourceIssue?->source_type === InventoryDocument::class
                ? InventoryDocument::query()->lockForUpdate()->find($sourceIssue->source_id)
                : null;
            $issueLine = $sourceDocument instanceof InventoryDocument
                ? InventoryDocumentLine::query()
                    ->where('inventory_document_id', $sourceDocument->getKey())
                    ->where('product_id', $line->product_id)
                    ->where('source_line_type', $line->source_line_type)
                    ->where('source_line_id', $line->source_line_id)
                    ->lockForUpdate()
                    ->first()
                : null;
            $accounting = $issueLine?->product_snapshot['maintenance_accounting'] ?? null;
            $journal = $sourceDocument?->journal_entry_id !== null
                ? JournalEntry::query()->with('lines')->lockForUpdate()->find($sourceDocument->journal_entry_id)
                : null;

            if (! $sourceIssue instanceof InventoryTransaction
                || ! $sourceDocument instanceof InventoryDocument
                || ! $issueLine instanceof InventoryDocumentLine
                || ! $journal instanceof JournalEntry
                || ! is_array($accounting)
                || $sourceDocument->document_type !== InventoryDocument::TypeMaintenanceMaterialIssue
                || $sourceDocument->status !== InventoryDocument::StatusPosted
                || $sourceDocument->source_document_type !== MaintenanceMaterialRequest::class
                || (int) $sourceDocument->source_document_id !== (int) $document->source_document_id
                || $sourceIssue->transaction_type !== InventoryDocument::TypeMaintenanceMaterialIssue
                || (int) $sourceIssue->company_id !== (int) $document->company_id
                || (int) $sourceIssue->branch_store_id !== (int) $document->branch_store_id
                || (int) $sourceIssue->product_id !== (int) $line->product_id
                || $sourceIssue->source_line_type !== $line->source_line_type
                || (int) $sourceIssue->source_line_id !== (int) $line->source_line_id
                || (int) $sourceDocument->journal_entry_id !== (int) ($accounting['journal_entry_id'] ?? 0)
                || $journal->status !== JournalEntry::StatusPosted
                || ! $journal->is_posted
                || $journal->source_type !== 'inventory_document_posting'
                || (int) $journal->source_id !== (int) $sourceDocument->getKey()
                || $sourceIssue->unit_cost === null
                || $sourceIssue->total_cost === null
                || bccomp((string) $sourceIssue->quantity_out, (string) $line->quantity, 8) < 0
                || $line->unit_cost === null
                || $line->total_cost === null
                || $issueLine->unit_cost === null
                || bccomp((string) $issueLine->unit_cost, (string) $sourceIssue->unit_cost, 8) !== 0
                || bccomp((string) $line->total_cost, (string) $sourceIssue->completedTotalCost(), 8) > 0
                || (! isset($line->product_snapshot['restoration_allocation_id'])
                    && bccomp((string) $line->total_cost, bcmul((string) $line->quantity,
                        bcdiv((string) $sourceIssue->completedTotalCost(), (string) $sourceIssue->quantity_out, 8), 8), 8) !== 0)) {
                throw new DomainException(__('The maintenance return cannot reverse its original issue accounting safely.'));
            }

            $inventoryLine = $journal->lines->firstWhere('id', (int) ($accounting['inventory_journal_line_id'] ?? 0));
            $expenseLine = $journal->lines->firstWhere('id', (int) ($accounting['expense_journal_line_id'] ?? 0));
            $costCenterId = $accounting['cost_center_id'] ?? null;
            $returnBasis = $this->maintenanceReturnBasis($line, $sourceIssue, $issueLine);
            $amount = $returnBasis['amount'];
            if (! $inventoryLine instanceof JournalEntryLine
                || ! $expenseLine instanceof JournalEntryLine
                || (int) $inventoryLine->account_id !== (int) ($accounting['inventory_account_id'] ?? 0)
                || (int) $expenseLine->account_id !== (int) ($accounting['expense_account_id'] ?? 0)
                || bccomp((string) $inventoryLine->credit_amount, '0', 4) <= 0
                || bccomp((string) $expenseLine->debit_amount, '0', 4) <= 0
                || bccomp($returnBasis['source_booked_amount'], $amount, 4) < 0
                || ($inventoryLine->cost_center_id === null ? null : (int) $inventoryLine->cost_center_id) !== ($costCenterId === null ? null : (int) $costCenterId)
                || ($expenseLine->cost_center_id === null ? null : (int) $expenseLine->cost_center_id) !== ($costCenterId === null ? null : (int) $costCenterId)) {
                throw new DomainException(__('The maintenance return source journal dimensions do not match its issue lineage.'));
            }

            $snapshot = $line->product_snapshot ?? [];
            $snapshot['maintenance_return_accounting'] = [...$returnBasis, 'rounding_rule' => 'source_proportional_gl_4_v1',
                'debit_account_id' => (int) $inventoryLine->account_id, 'credit_account_id' => (int) $expenseLine->account_id,
                'cost_center_id' => $costCenterId, 'source_issue_transaction_id' => (int) $sourceIssue->id];
            $line->forceFill(['product_snapshot' => $snapshot])->save();
            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }
            $lines[] = $this->reverseJournalLine($inventoryLine, $amount, 'debit_amount', $event);
            $lines[] = $this->reverseJournalLine($expenseLine, $amount, 'credit_amount', $event);
        }

        return $lines;
    }

    /** @return array<string, string> */
    private function maintenanceReturnBasis(InventoryDocumentLine $line, InventoryTransaction $issue, InventoryDocumentLine $issueLine): array
    {
        $sourceDocument = $issueLine->document;
        $journal = $sourceDocument->journalEntry;
        if (! $journal instanceof JournalEntry) {
            throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
        }
        $this->assertPostedJournalHeader($journal, (int) $issue->company_id, (int) $sourceDocument->financial_period_id,
            'inventory_document_posting', (int) $sourceDocument->id, (int) $sourceDocument->branch_id, $sourceDocument->document_date->toDateString());
        $previous = InventoryDocumentLine::query()->where('id', '<', $line->id)
            ->where('product_snapshot->source_issue_transaction_id', (int) $issue->id)
            ->whereHas('document', fn ($query) => $query->where('company_id', $issue->company_id)
                ->where('document_type', InventoryDocument::TypeMaintenanceMaterialReturn)
                ->where(fn ($query) => $query->where('status', InventoryDocument::StatusPosted)->orWhere('id', $line->inventory_document_id)))
            ->orderBy('id')->lockForUpdate()->get();
        $previousQuantity = $previous->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->quantity, 8), '0');
        $previousCost = $previous->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->total_cost, 8), '0');
        $quantity = bcadd($previousQuantity, (string) $line->quantity, 8);
        $cost = bcadd($previousCost, (string) $line->total_cost, 8);
        if (bccomp($quantity, (string) $issue->quantity_out, 8) > 0 || bccomp($cost, (string) $issue->completedTotalCost(), 8) > 0) {
            throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
        }
        $originalBooked = $issueLine->product_snapshot['inventory_accounting']['booked_amount'] ?? bcadd((string) $issueLine->total_cost, '0', 4);
        $expenseAccountId = (int) $issueLine->product_snapshot['maintenance_accounting']['expense_account_id'];
        $inventoryAccountId = (int) $issueLine->product_snapshot['maintenance_accounting']['inventory_account_id'];
        $sourceBooked = bcadd($originalBooked, $this->postedCorrectionAmount($issue, $expenseAccountId), 4);
        $inventoryBooked = bcsub($originalBooked, $this->postedCorrectionAmount($issue, $inventoryAccountId), 4);
        if (bccomp($sourceBooked, $inventoryBooked, 4) !== 0) {
            throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
        }
        $precision = $this->postedCorrectionAmount($issue, $expenseAccountId, true);
        $previousBooked = '0.0000';
        foreach ($previous as $prior) {
            $booked = $prior->product_snapshot['maintenance_return_accounting']['amount'] ?? bcadd((string) $prior->total_cost, '0', 4);
            $priorTransaction = InventoryTransaction::query()->where('posting_key', "inventory-document:{$prior->inventory_document_id}:line:{$prior->id}:in")->first();
            if ($priorTransaction) {
                $booked = bcsub($booked, $this->postedCorrectionAmount($priorTransaction, $expenseAccountId), 4);
            }
            $previousBooked = bcadd($previousBooked, $booked, 4);
        }
        $target = $this->returnedBookedTarget($issue, $sourceBooked, $quantity, $cost);
        $canonicalSource = bcadd($sourceBooked, bcsub(bcsub(bcround((string) $issueLine->total_cost, 4), $originalBooked, 4), $precision, 4), 4);
        $canonical = bcsub($this->returnedBookedTarget($issue, $canonicalSource, $quantity, $cost),
            $this->returnedBookedTarget($issue, $canonicalSource, $previousQuantity, $previousCost), 4);
        $amount = bcsub($target, $previousBooked, 4);
        if (bccomp($amount, '0', 4) < 0) {
            throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
        }

        return ['amount' => $amount, 'canonical_amount' => $canonical, 'source_booked_amount' => $sourceBooked,
            'previous_return_quantity' => $previousQuantity, 'previous_return_cost' => $previousCost, 'previous_return_booked' => $previousBooked];
    }

    private function postedCorrectionAmount(InventoryTransaction $source, int $accountId, bool $precisionOnly = false): string
    {
        $adjustments = InventoryValueAdjustment::query()->where('company_id', $source->company_id)
            ->where('status', InventoryValueAdjustment::StatusPosted)->whereHas('lines', fn ($query) => $query->where('source_transaction_id', $source->id))
            ->with(['lines' => fn ($query) => $query->orderBy('id'), 'journalEntry.lines'])->orderBy('id')->lockForUpdate()->get();
        $result = '0.0000';
        foreach ($adjustments as $adjustment) {
            $booked = $this->adjustmentBookedAmounts($adjustment);
            foreach ($adjustment->lines as $line) {
                if ((int) $line->source_transaction_id === (int) $source->id && (int) $line->account_id === $accountId
                    && (! $precisionOnly || $line->effect === InventoryValueAdjustmentLine::EffectGlPrecision)) {
                    $result = bcadd($result, $booked[$line->id], 4);
                }
            }
        }

        return $result;
    }

    /** @return array<int, string> */
    private function adjustmentBookedAmounts(InventoryValueAdjustment $adjustment): array
    {
        $adjustment->loadMissing(['lines', 'journalEntry.lines']);
        $groups = $expected = $booked = $actual = [];
        foreach ($adjustment->lines->sortBy('id') as $line) {
            $key = implode(':', [$line->account_id, $line->branch_id, $line->cost_center_id ?? 'none']);
            if ($line->effect === InventoryValueAdjustmentLine::EffectCounterpart) {
                $amount = bcmul((string) ($line->source_snapshot['rounded_gl_total'] ?? '0'), '-1', 4);
            } else {
                $prior = $groups[$key] ?? '0.00000000';
                $groups[$key] = bcadd($prior, (string) $line->amount, 8);
                $amount = bcsub(bcround($groups[$key], 4), bcround($prior, 4), 4);
            }
            $booked[$line->id] = $amount;
            $expected[$key] = bcadd($expected[$key] ?? '0', $amount, 4);
        }
        if ($adjustment->journalEntry) {
            $journal = $adjustment->journalEntry;
            $branches = $adjustment->lines->pluck('branch_id')->unique();
            $this->assertPostedJournalHeader($journal, (int) $adjustment->company_id, (int) $adjustment->financial_period_id,
                InventoryValueAdjustment::class, (int) $adjustment->id,
                $branches->count() === 1 ? (int) $branches->sole() : null, $adjustment->posting_date->toDateString());
            foreach ($journal->lines as $line) {
                if ($line->customer_id !== null || $line->supplier_id !== null || $line->employee_id !== null
                    || $line->department_id !== null || $line->bank_account_id !== null) {
                    throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
                }
                $key = implode(':', [$line->account_id, $line->branch_id, $line->cost_center_id ?? 'none']);
                $actual[$key] = bcadd($actual[$key] ?? '0', bcsub((string) $line->debit_amount, (string) $line->credit_amount, 4), 4);
            }
        }
        $expected = array_filter($expected, fn ($amount): bool => bccomp($amount, '0', 4) !== 0);
        $actual = array_filter($actual, fn ($amount): bool => bccomp($amount, '0', 4) !== 0);
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
        }

        return $booked;
    }

    private function returnedBookedTarget(InventoryTransaction $issue, string $sourceAmount, string $quantity, string $cost): string
    {
        if (bccomp($quantity, (string) $issue->quantity_out, 8) === 0) {
            return $sourceAmount;
        }
        if (bccomp((string) $issue->completedTotalCost(), '0', 8) === 0) {
            return '0.0000';
        }

        return bcround(bcdiv(bcmul($sourceAmount, $cost, 16), (string) $issue->completedTotalCost(), 16), 4);
    }

    /** @return list<array<string, mixed>> */
    private function maintenanceReturnSourcePairs(InventoryDocument $document): array
    {
        $pairs = [];
        foreach ($document->lines->sortBy('id') as $line) {
            $issue = InventoryTransaction::query()->where('company_id', $document->company_id)
                ->lockForUpdate()->findOrFail($line->product_snapshot['source_issue_transaction_id'] ?? null);
            $issueLine = InventoryDocumentLine::query()->where('inventory_document_id', $issue->source_id)
                ->where('product_id', $line->product_id)->where('source_line_type', $line->source_line_type)
                ->where('source_line_id', $line->source_line_id)->lockForUpdate()->sole();
            $source = $issueLine->product_snapshot['maintenance_accounting'] ?? null;
            if (! is_array($source) || $issue->source_type !== InventoryDocument::class
                || $issue->transaction_type !== InventoryDocument::TypeMaintenanceMaterialIssue
                || (int) $issue->product_id !== (int) $line->product_id || $line->total_cost === null) {
                throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
            }
            $basis = $this->maintenanceReturnBasis($line, $issue, $issueLine);
            $snapshot = $line->product_snapshot['maintenance_return_accounting'] ?? null;
            $pairs[] = ['document_line_id' => (int) $line->id, 'debit_account_id' => (int) $source['inventory_account_id'],
                'credit_account_id' => (int) $source['expense_account_id'], 'branch_id' => (int) $document->branch_id,
                'cost_center_id' => $source['cost_center_id'] === null ? null : (int) $source['cost_center_id'],
                'exact_total_cost' => (string) $line->total_cost, 'booked_amount' => $snapshot['amount'] ?? bcadd((string) $line->total_cost, '0', 4),
                'canonical_amount' => $basis['canonical_amount'], 'source_rule' => $snapshot['rounding_rule'] ?? 'legacy_truncate_line_gl_4'];
        }

        return $pairs;
    }

    private function attachMaintenanceIssueJournalLineage(InventoryDocument $document, JournalEntry $journal): void
    {
        $journal->loadMissing('lines');
        foreach ($document->lines as $line) {
            $snapshot = $line->product_snapshot ?? [];
            $accounting = $snapshot['maintenance_accounting'] ?? null;
            if (! is_array($accounting)) {
                throw new DomainException(__('Maintenance issue accounting lineage is missing.'));
            }

            $costCenterId = $accounting['cost_center_id'] ?? null;
            $inventoryLine = $journal->lines->first(fn (JournalEntryLine $journalLine): bool => (int) $journalLine->account_id === (int) $accounting['inventory_account_id']
                && bccomp((string) $journalLine->credit_amount, '0', 4) > 0
                && ($journalLine->cost_center_id === null ? null : (int) $journalLine->cost_center_id) === ($costCenterId === null ? null : (int) $costCenterId));
            $expenseLine = $journal->lines->first(fn (JournalEntryLine $journalLine): bool => (int) $journalLine->account_id === (int) $accounting['expense_account_id']
                && bccomp((string) $journalLine->debit_amount, '0', 4) > 0
                && ($journalLine->cost_center_id === null ? null : (int) $journalLine->cost_center_id) === ($costCenterId === null ? null : (int) $costCenterId));
            if (! $inventoryLine instanceof JournalEntryLine || ! $expenseLine instanceof JournalEntryLine) {
                throw new DomainException(__('Maintenance issue accounting lineage could not be attached to its journal.'));
            }

            $snapshot['maintenance_accounting'] = [
                ...$accounting,
                'journal_entry_id' => (int) $journal->getKey(),
                'inventory_journal_line_id' => (int) $inventoryLine->getKey(),
                'expense_journal_line_id' => (int) $expenseLine->getKey(),
            ];
            $line->forceFill(['product_snapshot' => $snapshot])->save();
        }
    }

    /** @return array<string, mixed> */
    private function reverseJournalLine(JournalEntryLine $source, string $amount, string $side, string $event): array
    {
        return [
            'account_id' => (int) $source->account_id,
            'debit_amount' => $side === 'debit_amount' ? $amount : '0.0000',
            'credit_amount' => $side === 'credit_amount' ? $amount : '0.0000',
            'description' => $event,
            'customer_id' => $source->customer_id,
            'supplier_id' => $source->supplier_id,
            'employee_id' => $source->employee_id,
            'bank_account_id' => $source->bank_account_id,
            'cost_center_id' => $source->cost_center_id,
            'department_id' => $source->department_id,
            'branch_id' => $source->branch_id,
        ];
    }

    /** @param array<string, array<string, mixed>> $grouped */
    private function addGroupedLine(
        array &$grouped,
        int $accountId,
        string $debit,
        string $credit,
        string $description,
        mixed $costCenterId,
        ?int $branchId = null,
    ): void {
        $side = bccomp($debit, '0', 4) > 0 ? 'debit' : 'credit';
        $key = $accountId.':'.$side.':'.($costCenterId ?? 'none').':'.($branchId ?? 'header');
        $grouped[$key] ??= [
            'account_id' => $accountId,
            'debit_amount' => '0.0000',
            'credit_amount' => '0.0000',
            'description' => $description,
            'cost_center_id' => $costCenterId,
            'branch_id' => $branchId,
        ];
        $grouped[$key]['debit_amount'] = bcadd((string) $grouped[$key]['debit_amount'], $debit, 4);
        $grouped[$key]['credit_amount'] = bcadd((string) $grouped[$key]['credit_amount'], $credit, 4);
    }

    /** @return array<string, mixed> */
    private function header(InventoryDocument $document, string $sourceType, string $description): array
    {
        $currencyId = Currency::query()
            ->forCompany((int) $document->company_id)
            ->active()
            ->where('is_main', true)
            ->value('id');

        if ($currencyId === null) {
            throw new DomainException(__('inventory_accounting.errors.main_currency_required'));
        }

        return [
            'entry_date' => $document->document_date,
            'company_id' => (int) $document->company_id,
            'financial_period_id' => (int) $document->financial_period_id,
            'branch_id' => (int) $document->branch_id,
            'currency_id' => (int) $currencyId,
            'exchange_rate' => 1,
            'description' => $description,
            'notes' => $document->notes,
            'source_type' => $sourceType,
            'source_id' => (int) $document->getKey(),
            'source_doc_num' => (string) $document->doc_num,
        ];
    }

    private function eventLabel(InventoryDocument $document): string
    {
        return match ($document->document_type) {
            InventoryDocument::TypeReceipt => __('inventory.movements.accounting.receipt', ['document' => $document->doc_num]),
            InventoryDocument::TypeIssue => __('inventory.movements.accounting.issue', ['document' => $document->doc_num]),
            InventoryDocument::TypeReturn => __('inventory.movements.accounting.return', ['document' => $document->doc_num]),
            InventoryDocument::TypeMaterialIssue => __('Production material issue :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeAdditionalMaterialIssue => __('Additional production material issue :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeMaterialReturn => __('Production material return :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeProductionWaste => __('Production waste :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeProductionReceipt => __('Finished goods receipt :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeAdjustmentIn => __('Inventory count surplus :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeAdjustmentOut => __('Inventory count shortage :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeMaintenanceMaterialIssue => __('Maintenance material issue :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeMaintenanceMaterialReturn => __('Maintenance material return :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeScrap => __('Warehouse scrap :document', ['document' => $document->doc_num]),
            default => __('Inventory movement :document', ['document' => $document->doc_num]),
        };
    }

    private function isMaintenanceMaterialDocument(InventoryDocument $document): bool
    {
        return in_array($document->document_type, [
            InventoryDocument::TypeMaintenanceMaterialIssue,
            InventoryDocument::TypeMaintenanceMaterialReturn,
        ], true);
    }

    private function maintenanceCostCenterId(InventoryDocument $document): ?int
    {
        if ($document->source_document_type !== MaintenanceMaterialRequest::class) {
            throw new DomainException(__('Maintenance inventory accounting requires its material request source.'));
        }

        $request = MaintenanceMaterialRequest::query()
            ->with(['workOrder.productionRun', 'workOrder.asset'])
            ->lockForUpdate()
            ->findOrFail($document->source_document_id);

        if ((int) $request->company_id !== (int) $document->company_id
            || (int) $request->financial_period_id !== (int) $document->financial_period_id
            || (int) $request->branch_id !== (int) $document->branch_id
            || (int) $request->branch_store_id !== (int) $document->branch_store_id) {
            throw new DomainException(__('Maintenance inventory accounting source context does not match the inventory document.'));
        }

        $costCenterId = $request->workOrder?->productionRun?->cost_center_id
            ?? $request->workOrder?->asset?->cost_center_id;

        return $costCenterId === null ? null : (int) $costCenterId;
    }
}
