<?php

namespace Modules\Finance\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\OpeningBalance;

class OpeningBalanceApprovalService
{
    public function __construct(
        private readonly JournalEntryService $journalEntries,
        private readonly OperatingContextService $operatingContext,
    ) {}

    public function approve(OpeningBalance $openingBalance): OpeningBalance
    {
        return DB::transaction(function () use ($openingBalance): OpeningBalance {
            /** @var OpeningBalance $record */
            $record = OpeningBalance::query()
                ->with(['lines.account', 'financialPeriod'])
                ->lockForUpdate()
                ->findOrFail($openingBalance->getKey());

            $this->assertApprovable($record);

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
    }

    private function assertLinesAreValid(OpeningBalance $record): void
    {
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $seen = [];

        foreach ($record->lines as $line) {
            $debit = (float) $line->debit_amount;
            $credit = (float) $line->credit_amount;

            if (($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) {
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
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        if (round($totalDebit, 4) !== round($totalCredit, 4)) {
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
