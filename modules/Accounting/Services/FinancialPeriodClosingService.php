<?php

namespace Modules\Accounting\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;

class FinancialPeriodClosingService
{
    private const ClosingSource = 'period_closing';

    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly PostingAccountResolver $accounts,
        private readonly NumericFormatService $numbers,
        private readonly OperatingCompanyContextService $companyContext,
        private readonly PeriodClosePreflightService $preflight,
    ) {}

    /**
     * @return array{
     *     period: FinancialPeriod,
     *     checks: list<array{key: string, status: 'pass'|'warning'|'blocker', message: string, count?: int}>,
     *     has_blockers: bool,
     *     trial_balance: array{debit: string, credit: string, difference: string},
     *     closing_plan: array{lines: list<array<string, mixed>>, period_result: string, total_debit: string, total_credit: string}|null,
     *     closing_plan_error: string|null,
     *     existing_closing_entry: JournalEntry|null,
     *     next_period: FinancialPeriod|null
     * }
     */
    public function preview(FinancialPeriod $period): array
    {
        $this->assertCurrentCompany($period);

        $draftCount = $this->draftJournalCount($period);
        $trialBalance = $this->trialBalance($period);
        $closingPlan = null;
        $closingPlanError = null;

        try {
            $closingPlan = $this->closingPlan($period);
        } catch (DomainException $exception) {
            $closingPlanError = $exception->getMessage();
        }

        $checks = [
            [
                'key' => 'period_type_policy',
                'status' => 'warning',
                'message' => __('financial_periods.closing.checks.period_type_policy'),
            ],
            [
                'key' => 'draft_journals',
                'status' => $draftCount > 0 ? 'blocker' : 'pass',
                'message' => $draftCount > 0
                    ? trans_choice('financial_periods.messages.draft_journals_block_close', $draftCount, ['count' => $draftCount])
                    : __('financial_periods.closing.checks.no_draft_journals'),
                'count' => $draftCount,
            ],
            [
                'key' => 'trial_balance',
                'status' => bccomp($trialBalance['difference'], '0', 4) === 0 ? 'pass' : 'blocker',
                'message' => bccomp($trialBalance['difference'], '0', 4) === 0
                    ? __('financial_periods.closing.checks.trial_balance_balanced')
                    : __('financial_periods.messages.unbalanced_trial_balance', [
                        'debit' => $trialBalance['debit'],
                        'credit' => $trialBalance['credit'],
                    ]),
            ],
        ];

        if ($closingPlanError !== null) {
            $checks[] = [
                'key' => 'retained_earnings',
                'status' => 'blocker',
                'message' => $closingPlanError,
            ];
        } else {
            $checks[] = [
                'key' => 'retained_earnings',
                'status' => 'pass',
                'message' => __('financial_periods.closing.checks.closing_mapping_ready'),
            ];
        }

        array_push($checks, ...$this->preflight->checks($period));

        return [
            'period' => $period,
            'checks' => $checks,
            'has_blockers' => collect($checks)->contains(
                fn (array $check): bool => $check['status'] === 'blocker',
            ),
            'trial_balance' => $trialBalance,
            'closing_plan' => $closingPlan,
            'closing_plan_error' => $closingPlanError,
            'existing_closing_entry' => $this->latestClosingEntry($period),
            'next_period' => FinancialPeriod::query()
                ->forCompany((int) $period->company_id)
                ->whereDate('from_date', '>', $period->to_date?->toDateString())
                ->orderBy('from_date')
                ->first(),
        ];
    }

    /**
     * @return array{period: FinancialPeriod, journal_entry: JournalEntry|null, already_closed: bool}
     */
    public function close(FinancialPeriod $period): array
    {
        return DB::transaction(function () use ($period): array {
            $period = $this->lockedPeriod($period);
            $this->assertCurrentCompany($period);

            if ($period->is_closed) {
                return [
                    'period' => $period,
                    'journal_entry' => $this->latestClosingEntry($period),
                    'already_closed' => true,
                ];
            }

            $this->assertNoDraftJournals($period);
            $this->assertTrialBalanceIsBalanced($period);
            $this->preflight->assertReady($period);
            $closingPlan = $this->closingPlan($period);
            $entry = $closingPlan['lines'] === [] ? null : $this->createClosingEntry($period, $closingPlan['lines']);

            $period->forceFill([
                'is_closed' => true,
                'updated_by' => auth()->id(),
            ])->save();

            return [
                'period' => $period->refresh(),
                'journal_entry' => $entry,
                'already_closed' => false,
            ];
        }, attempts: 3);
    }

    /**
     * @return array{period: FinancialPeriod, reversal_entry: JournalEntry|null, already_open: bool}
     */
    public function reopen(FinancialPeriod $period): array
    {
        return DB::transaction(function () use ($period): array {
            $period = $this->lockedPeriod($period);
            $this->assertCurrentCompany($period);

            if (! $period->is_closed) {
                return [
                    'period' => $period,
                    'reversal_entry' => null,
                    'already_open' => true,
                ];
            }

            $closingEntry = $this->latestUnreversedClosingEntry($period);
            if (! $closingEntry instanceof JournalEntry && $this->incomeAccountBalances($period) !== []) {
                throw new DomainException(__('financial_periods.messages.legacy_close_requires_review'));
            }

            $period->forceFill([
                'is_closed' => false,
                'updated_by' => auth()->id(),
            ])->save();

            $reversal = $closingEntry instanceof JournalEntry
                ? $this->journals->createPostedReversalFromSource($closingEntry, [
                    'entry_date' => $period->to_date,
                    'company_id' => (int) $period->company_id,
                    'financial_period_id' => (int) $period->getKey(),
                    'branch_id' => null,
                    'currency_id' => (int) $closingEntry->currency_id,
                    'exchange_rate' => '1.000000',
                    'description' => __('financial_periods.journal.reopening_description', ['period' => $period->doc_num]),
                    'notes' => null,
                    'source_type' => $closingEntry->source_type.'_reversal',
                    'source_id' => (int) $period->getKey(),
                    'source_doc_num' => (string) $period->doc_num,
                ])
                : null;

            return [
                'period' => $period->refresh(),
                'reversal_entry' => $reversal,
                'already_open' => false,
            ];
        }, attempts: 3);
    }

    private function lockedPeriod(FinancialPeriod $period): FinancialPeriod
    {
        return FinancialPeriod::query()
            ->whereKey($period->getKey())
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertCurrentCompany(FinancialPeriod $period): void
    {
        abort_unless(
            (int) $period->company_id === $this->companyContext->requireCompanyId(),
            404,
        );
    }

    private function assertNoDraftJournals(FinancialPeriod $period): void
    {
        $draftCount = JournalEntry::query()
            ->where('company_id', $period->company_id)
            ->where('financial_period_id', $period->getKey())
            ->where('status', JournalEntry::StatusDraft)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->count();

        if ($draftCount > 0) {
            throw new DomainException(trans_choice('financial_periods.messages.draft_journals_block_close', $draftCount, [
                'count' => $draftCount,
            ]));
        }
    }

    private function assertTrialBalanceIsBalanced(FinancialPeriod $period): void
    {
        $trialBalance = $this->trialBalance($period);

        if (bccomp($trialBalance['difference'], '0', 4) !== 0) {
            throw new DomainException(__('financial_periods.messages.unbalanced_trial_balance', [
                'debit' => $trialBalance['debit'],
                'credit' => $trialBalance['credit'],
            ]));
        }
    }

    private function draftJournalCount(FinancialPeriod $period): int
    {
        return JournalEntry::query()
            ->where('company_id', $period->company_id)
            ->where('financial_period_id', $period->getKey())
            ->where('status', JournalEntry::StatusDraft)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * @return array{debit: string, credit: string, difference: string}
     */
    private function trialBalance(FinancialPeriod $period): array
    {
        $debit = '0.0000';
        $credit = '0.0000';

        foreach ($this->periodLines($period)->cursor() as $line) {
            $rate = $this->numbers->normalizeToScale($line->exchange_rate, 6) ?? '1.000000';
            $debit = bcadd($debit, bcmul((string) $line->debit_amount, $rate, 4), 4);
            $credit = bcadd($credit, bcmul((string) $line->credit_amount, $rate, 4), 4);
        }

        return [
            'debit' => $debit,
            'credit' => $credit,
            'difference' => bcsub($debit, $credit, 4),
        ];
    }

    /**
     * @return array<int, array{account: Account, signed: string}>
     */
    private function incomeAccountBalances(FinancialPeriod $period): array
    {
        $accounts = Account::query()
            ->withTrashed()
            ->forCompany((int) $period->company_id)
            ->where('statement_type', Account::StatementIncomeStatement)
            ->get(['id', 'doc_num', 'account_code', 'name', 'name_en', 'company_id'])
            ->keyBy(fn (Account $account): int => (int) $account->getKey());
        $balances = [];

        foreach ($this->periodLines($period)
            ->whereIn('journal_entry_lines.account_id', $accounts->keys()->all())
            ->cursor() as $line) {
            $accountId = (int) $line->account_id;
            $rate = $this->numbers->normalizeToScale($line->exchange_rate, 6) ?? '1.000000';
            $debit = bcmul((string) $line->debit_amount, $rate, 4);
            $credit = bcmul((string) $line->credit_amount, $rate, 4);
            $balances[$accountId] = bcadd(
                $balances[$accountId] ?? '0.0000',
                bcsub($debit, $credit, 4),
                4,
            );
        }

        return collect($balances)
            ->filter(fn (string $signed): bool => bccomp($signed, '0', 4) !== 0)
            ->mapWithKeys(fn (string $signed, int $accountId): array => [
                $accountId => [
                    'account' => $accounts->get($accountId),
                    'signed' => $signed,
                ],
            ])
            ->all();
    }

    /**
     * @return array{lines: list<array<string, mixed>>, period_result: string, total_debit: string, total_credit: string}
     */
    private function closingPlan(FinancialPeriod $period): array
    {
        $balances = $this->incomeAccountBalances($period);

        if ($balances === []) {
            return [
                'lines' => [],
                'period_result' => '0.0000',
                'total_debit' => '0.0000',
                'total_credit' => '0.0000',
            ];
        }

        $lines = [];
        $debit = '0.0000';
        $credit = '0.0000';

        foreach ($balances as $balance) {
            $signed = $balance['signed'];
            $amount = bccomp($signed, '0', 4) > 0 ? $signed : bcmul($signed, '-1', 4);
            $line = [
                'account_id' => (int) $balance['account']->getKey(),
                'account_doc_num' => (string) $balance['account']->doc_num,
                'account_label' => $balance['account']->codeNameLabel(),
                'debit_amount' => bccomp($signed, '0', 4) < 0 ? $amount : '0.0000',
                'credit_amount' => bccomp($signed, '0', 4) > 0 ? $amount : '0.0000',
                'description' => __('financial_periods.journal.close_account', [
                    'account' => $balance['account']->codeNameLabel(),
                ]),
            ];
            $debit = bcadd($debit, $line['debit_amount'], 4);
            $credit = bcadd($credit, $line['credit_amount'], 4);
            $lines[] = $line;
        }

        $periodResult = bcsub($debit, $credit, 4);
        if (bccomp($periodResult, '0', 4) !== 0) {
            $retainedEarnings = $this->accounts->resolve(
                (int) $period->company_id,
                'retained_earnings',
                __('financial_periods.journal.closing_event'),
            );
            $amount = bccomp($periodResult, '0', 4) > 0 ? $periodResult : bcmul($periodResult, '-1', 4);
            $lines[] = [
                'account_id' => (int) $retainedEarnings->getKey(),
                'account_doc_num' => (string) $retainedEarnings->doc_num,
                'account_label' => $retainedEarnings->codeNameLabel(),
                'debit_amount' => bccomp($periodResult, '0', 4) < 0 ? $amount : '0.0000',
                'credit_amount' => bccomp($periodResult, '0', 4) > 0 ? $amount : '0.0000',
                'description' => __('financial_periods.journal.transfer_result'),
            ];

            $debit = bcadd($debit, bccomp($periodResult, '0', 4) < 0 ? $amount : '0.0000', 4);
            $credit = bcadd($credit, bccomp($periodResult, '0', 4) > 0 ? $amount : '0.0000', 4);
        }

        return [
            'lines' => array_values($lines),
            'period_result' => $periodResult,
            'total_debit' => $debit,
            'total_credit' => $credit,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function createClosingEntry(FinancialPeriod $period, array $lines): JournalEntry
    {
        $journalLines = array_map(fn (array $line): array => [
            'account_id' => $line['account_id'],
            'debit_amount' => $line['debit_amount'],
            'credit_amount' => $line['credit_amount'],
            'description' => $line['description'],
        ], $lines);

        $sourceType = $this->nextClosingSourceType($period);
        $currency = Currency::query()
            ->forCompany((int) $period->company_id)
            ->active()
            ->where('is_main', true)
            ->firstOrFail();

        return $this->journals->createPostedFromSource([
            'entry_date' => $period->to_date,
            'company_id' => (int) $period->company_id,
            'financial_period_id' => (int) $period->getKey(),
            'branch_id' => null,
            'currency_id' => (int) $currency->getKey(),
            'exchange_rate' => '1.000000',
            'description' => __('financial_periods.journal.closing_description', ['period' => $period->doc_num]),
            'notes' => null,
            'source_type' => $sourceType,
            'source_id' => (int) $period->getKey(),
            'source_doc_num' => (string) $period->doc_num,
        ], $journalLines);
    }

    private function periodLines(FinancialPeriod $period): Builder
    {
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.company_id', $period->company_id)
            ->where('journal_entries.financial_period_id', $period->getKey())
            ->whereDate('journal_entries.entry_date', '>=', $period->from_date?->toDateString())
            ->whereDate('journal_entries.entry_date', '<=', $period->to_date?->toDateString())
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->where('journal_entries.is_posted', true)
            ->select([
                'journal_entry_lines.account_id',
                'journal_entry_lines.debit_amount',
                'journal_entry_lines.credit_amount',
                'journal_entries.exchange_rate',
            ]);
    }

    private function nextClosingSourceType(FinancialPeriod $period): string
    {
        $sequence = JournalEntry::query()
            ->where('company_id', $period->company_id)
            ->where('financial_period_id', $period->getKey())
            ->where('source_doc_num', $period->doc_num)
            ->where('source_type', 'like', self::ClosingSource.'%')
            ->where('source_type', 'not like', '%reversal%')
            ->count() + 1;

        return $sequence === 1 ? self::ClosingSource : self::ClosingSource.'_'.($sequence);
    }

    private function latestClosingEntry(FinancialPeriod $period): ?JournalEntry
    {
        return $this->closingEntries($period)->latest('id')->first();
    }

    private function latestUnreversedClosingEntry(FinancialPeriod $period): ?JournalEntry
    {
        return $this->closingEntries($period)
            ->whereNull('reversed_entry_id')
            ->latest('id')
            ->first();
    }

    private function closingEntries(FinancialPeriod $period): \Illuminate\Database\Eloquent\Builder
    {
        return JournalEntry::query()
            ->where('company_id', $period->company_id)
            ->where('financial_period_id', $period->getKey())
            ->where('source_doc_num', $period->doc_num)
            ->where('source_type', 'like', self::ClosingSource.'%')
            ->where('source_type', 'not like', '%reversal%');
    }
}
