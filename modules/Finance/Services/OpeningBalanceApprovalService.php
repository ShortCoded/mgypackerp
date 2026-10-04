<?php

namespace Modules\Finance\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\OpeningBalance;

class OpeningBalanceApprovalService
{
    public function __construct(
        private readonly JournalEntryService $journalEntries,
        private readonly OperatingContextService $operatingContext,
        private readonly OpeningInventoryValuationService $inventoryValuation,
    ) {}

    public function approve(OpeningBalance $openingBalance): OpeningBalance
    {
        return DB::transaction(function () use ($openingBalance): OpeningBalance {
            $context = $this->currentContext();
            if ((int) $openingBalance->company_id !== $context['company_id']
                || (int) $openingBalance->financial_period_id !== $context['financial_period_id']) {
                throw new DomainException(__('operating_context.messages.required'));
            }
            Company::query()->whereKey($context['company_id'])->lockForUpdate()->firstOrFail();
            FinancialPeriod::query()->where('company_id', $context['company_id'])
                ->whereKey($context['financial_period_id'])->lockForUpdate()->firstOrFail();
            /** @var OpeningBalance $record */
            $record = OpeningBalance::query()
                ->with(['lines.account', 'financialPeriod', 'currency'])
                ->lockForUpdate()
                ->findOrFail($openingBalance->getKey());

            $this->assertApprovable($record);
            if (is_array($record->inventory_valuation_snapshot)
                && (int) $record->inventory_valuation_snapshot['branch_id'] !== (int) $this->operatingContext->snapshot(request())['branch_id']) {
                throw new DomainException(__('opening_balances.messages.inventory_source_changed'));
            }
            $this->inventoryValuation->assertApproval($record);

            $journalEntry = $this->journalEntries->createPostedFromOpeningBalance($record);

            $record->forceFill([
                'approved' => true,
                'approved_at' => now(),
                'approved_by' => auth()->id(),
                'journal_entry_id' => $journalEntry->getKey(),
                'status' => OpeningBalance::StatusApproved,
                'is_closed' => true,
                'updated_by' => auth()->id(),
            ])->save();

            return $record->refresh()->load(['lines.account', 'journalEntry']);
        });
    }

    /**
     * @param  list<string>  $docNums
     * @return array{approved: int, skipped: int, skipped_doc_nums: list<string>}
     */
    public function bulkApprove(array $docNums): array
    {
        return DB::transaction(function () use ($docNums): array {
            $context = $this->currentContext();
            $approved = 0;
            $skippedDocNums = [];

            $records = OpeningBalance::query()
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->whereIn('doc_num', $docNums)
                ->orderBy('doc_number')
                ->get()
                ->keyBy('doc_num');

            foreach ($docNums as $docNum) {
                $record = $records->get($docNum);

                if (! $record instanceof OpeningBalance) {
                    $skippedDocNums[] = $docNum;

                    continue;
                }

                try {
                    $this->approve($record);
                    $approved++;
                } catch (DomainException) {
                    $skippedDocNums[] = $docNum;
                }
            }

            return [
                'approved' => $approved,
                'skipped' => count($skippedDocNums),
                'skipped_doc_nums' => array_values($skippedDocNums),
            ];
        });
    }

    private function assertApprovable(OpeningBalance $record): void
    {
        if ($record->approved || $record->status === OpeningBalance::StatusApproved) {
            throw new DomainException(__('opening_balances.messages.already_approved'));
        }

        if ($record->is_cancelled || $record->status === OpeningBalance::StatusCancelled) {
            throw new DomainException(__('opening_balances.messages.cancelled_not_approvable'));
        }

        if ($record->journal_entry_id !== null) {
            throw new DomainException(__('opening_balances.messages.already_has_journal_entry'));
        }

        if ($record->lines->isEmpty()) {
            throw new DomainException(__('opening_balances.messages.lines_required'));
        }

        $this->assertPeriodAllowsOpeningEntries($record);
        $this->assertLinesAreValid($record);
    }

    private function assertPeriodAllowsOpeningEntries(OpeningBalance $record): void
    {
        if ($record->financialPeriod?->is_closed) {
            throw new DomainException(__('opening_balances.messages.period_closed'));
        }

        if ($record->financialPeriod && $record->financialPeriod->allows_opening_entries === false) {
            throw new DomainException(__('opening_balances.messages.period_disallows_opening_entries'));
        }

        if ($record->financialPeriod && JournalEntry::query()
            ->where('company_id', $record->company_id)
            ->where('status', JournalEntry::StatusPosted)
            ->where('is_posted', true)
            ->whereDate('entry_date', '<', $record->financialPeriod->from_date)
            ->exists()) {
            throw new DomainException(__('opening_balances.messages.history_derived_opening_only'));
        }
    }

    private function assertLinesAreValid(OpeningBalance $record): void
    {
        $totalDebit = '0.0000';
        $totalCredit = '0.0000';
        $seen = [];

        foreach ($record->lines as $line) {
            $debit = (string) $line->debit_amount;
            $credit = (string) $line->credit_amount;

            if ((bccomp($debit, '0', 4) > 0 && bccomp($credit, '0', 4) > 0) || (bccomp($debit, '0', 4) <= 0 && bccomp($credit, '0', 4) <= 0)) {
                throw new DomainException(__('opening_balances.messages.line_side_invalid'));
            }

            if (! $line->account instanceof Account || ! $line->account->is_postable || $line->account->is_group || $line->account->status !== 'active') {
                throw new DomainException(__('opening_balances.messages.account_not_postable'));
            }

            $key = implode(':', [
                $line->account_id,
                $line->customer_id ?? 'null',
                $line->supplier_id ?? 'null',
                $line->employee_id ?? 'null',
                $line->bank_account_id ?? 'null',
                $line->cost_center_id ?? 'null',
                $line->branch_id ?? 'null',
            ]);

            if (isset($seen[$key])) {
                throw new DomainException(__('opening_balances.messages.duplicate_line'));
            }

            $seen[$key] = true;
            $totalDebit = bcadd($totalDebit, $debit, 4);
            $totalCredit = bcadd($totalCredit, $credit, 4);
        }

        if (bccomp($totalDebit, $totalCredit, 4) !== 0) {
            throw new DomainException(__('opening_balances.messages.unbalanced'));
        }
    }

    /**
     * @return array{company_id: int, financial_period_id: int}
     */
    private function currentContext(): array
    {
        $context = $this->operatingContext->snapshot(request());

        if (! $context['company_id'] || ! $context['financial_period_id']) {
            throw new DomainException(__('operating_context.messages.required'));
        }

        return [
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
        ];
    }
}
