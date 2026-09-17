<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Currency;
use Modules\Core\Services\NumericFormatService;

class TrialBalanceQueryService
{
    public function __construct(private readonly NumericFormatService $numbers) {}

    /**
     * @param  array{
     *     company_id: int,
     *     from_date: string,
     *     to_date: string,
     *     branch_id?: int|null,
     *     cost_center_id?: int|null,
     *     include_zero?: bool
     * }  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $accounts = Account::query()
            ->withTrashed()
            ->forCompany($filters['company_id'])
            ->ordered()
            ->get([
                'id',
                'doc_num',
                'account_code',
                'name',
                'name_en',
                'parent_id',
                'level',
                'is_group',
                'is_postable',
                'status',
                'deleted_at',
            ]);

        $direct = $this->directBalances($filters, $accounts->modelKeys());
        $children = $accounts
            ->groupBy(fn (Account $account): int => (int) ($account->parent_id ?? 0))
            ->map(fn (Collection $children): array => $children->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())
            ->all();
        $aggregates = [];
        $visiting = [];

        foreach ($accounts as $account) {
            $this->aggregate((int) $account->getKey(), $children, $direct, $aggregates, $visiting);
        }

        $includeZero = (bool) ($filters['include_zero'] ?? false);
        $rows = $accounts
            ->map(function (Account $account) use ($aggregates): array {
                $balance = $aggregates[(int) $account->getKey()] ?? $this->emptyBalance();
                $endingSigned = bcadd(
                    $balance['opening_signed'],
                    bcsub($balance['period_debit'], $balance['period_credit'], 4),
                    4,
                );
                [$openingDebit, $openingCredit] = $this->splitSigned($balance['opening_signed']);
                [$endingDebit, $endingCredit] = $this->splitSigned($endingSigned);

                return [
                    'id' => (int) $account->getKey(),
                    'doc_num' => (string) $account->doc_num,
                    'account_code' => (string) $account->account_code,
                    'name' => $account->displayName(),
                    'level' => (int) $account->level,
                    'is_group' => (bool) $account->is_group,
                    'is_postable' => (bool) $account->is_postable,
                    'is_inactive' => $account->status !== 'active' || $account->trashed(),
                    'opening_debit' => $openingDebit,
                    'opening_credit' => $openingCredit,
                    'period_debit' => $balance['period_debit'],
                    'period_credit' => $balance['period_credit'],
                    'ending_debit' => $endingDebit,
                    'ending_credit' => $endingCredit,
                    'has_activity' => $this->hasActivity($balance),
                ];
            })
            ->when(! $includeZero, fn (Collection $rows): Collection => $rows->where('has_activity', true))
            ->values()
            ->all();

        $totals = $this->totals($direct);

        return [
            'currency' => Currency::query()
                ->forCompany($filters['company_id'])
                ->active()
                ->where('is_main', true)
                ->first(['doc_num', 'code', 'name'])?->only(['doc_num', 'code', 'name']),
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $totals,
            'is_balanced' => bccomp($totals['opening_debit'], $totals['opening_credit'], 4) === 0
                && bccomp($totals['period_debit'], $totals['period_credit'], 4) === 0
                && bccomp($totals['ending_debit'], $totals['ending_credit'], 4) === 0,
            'generated_at' => now(),
            'generated_by' => auth()->user()?->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $accountIds
     * @return array<int, array{opening_signed: string, period_debit: string, period_credit: string}>
     */
    private function directBalances(array $filters, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $balances = [];

        foreach ($this->movementQuery($filters, $accountIds)->cursor() as $row) {
            $accountId = (int) $row->account_id;
            $balances[$accountId] ??= $this->emptyBalance();
            $debit = $this->baseAmount($row->debit_amount, $row->exchange_rate);
            $credit = $this->baseAmount($row->credit_amount, $row->exchange_rate);

            if ((string) $row->entry_date < $filters['from_date']) {
                $balances[$accountId]['opening_signed'] = bcadd(
                    $balances[$accountId]['opening_signed'],
                    bcsub($debit, $credit, 4),
                    4,
                );

                continue;
            }

            $balances[$accountId]['period_debit'] = bcadd($balances[$accountId]['period_debit'], $debit, 4);
            $balances[$accountId]['period_credit'] = bcadd($balances[$accountId]['period_credit'], $credit, 4);
        }

        return $balances;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $accountIds
     */
    private function movementQuery(array $filters, array $accountIds): Builder
    {
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.company_id', $filters['company_id'])
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->where('journal_entries.is_posted', true)
            ->whereDate('journal_entries.entry_date', '<=', $filters['to_date'])
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId): Builder => $query->where(function (Builder $query) use ($branchId): void {
                $query->where('journal_entry_lines.branch_id', $branchId)
                    ->orWhere(function (Builder $query) use ($branchId): void {
                        $query->whereNull('journal_entry_lines.branch_id')
                            ->where('journal_entries.branch_id', $branchId);
                    });
            }))
            ->when($filters['cost_center_id'] ?? null, fn (Builder $query, int $costCenterId): Builder => $query->where('journal_entry_lines.cost_center_id', $costCenterId))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entry_lines.id')
            ->select([
                'journal_entry_lines.account_id',
                'journal_entry_lines.debit_amount',
                'journal_entry_lines.credit_amount',
                'journal_entries.entry_date',
                'journal_entries.exchange_rate',
            ]);
    }

    /**
     * @param  array<int, list<int>>  $children
     * @param  array<int, array{opening_signed: string, period_debit: string, period_credit: string}>  $direct
     * @param  array<int, array{opening_signed: string, period_debit: string, period_credit: string}>  $aggregates
     * @param  array<int, bool>  $visiting
     * @return array{opening_signed: string, period_debit: string, period_credit: string}
     */
    private function aggregate(int $accountId, array $children, array $direct, array &$aggregates, array &$visiting): array
    {
        if (isset($aggregates[$accountId])) {
            return $aggregates[$accountId];
        }

        if (isset($visiting[$accountId])) {
            return $direct[$accountId] ?? $this->emptyBalance();
        }

        $visiting[$accountId] = true;
        $balance = $direct[$accountId] ?? $this->emptyBalance();

        foreach ($children[$accountId] ?? [] as $childId) {
            $child = $this->aggregate($childId, $children, $direct, $aggregates, $visiting);
            $balance['opening_signed'] = bcadd($balance['opening_signed'], $child['opening_signed'], 4);
            $balance['period_debit'] = bcadd($balance['period_debit'], $child['period_debit'], 4);
            $balance['period_credit'] = bcadd($balance['period_credit'], $child['period_credit'], 4);
        }

        unset($visiting[$accountId]);

        return $aggregates[$accountId] = $balance;
    }

    /**
     * @param  array<int, array{opening_signed: string, period_debit: string, period_credit: string}>  $direct
     * @return array{opening_debit: string, opening_credit: string, period_debit: string, period_credit: string, ending_debit: string, ending_credit: string}
     */
    private function totals(array $direct): array
    {
        $openingDebit = '0.0000';
        $openingCredit = '0.0000';
        $periodDebit = '0.0000';
        $periodCredit = '0.0000';
        $endingDebit = '0.0000';
        $endingCredit = '0.0000';

        foreach ($direct as $balance) {
            [$accountOpeningDebit, $accountOpeningCredit] = $this->splitSigned($balance['opening_signed']);
            [$accountEndingDebit, $accountEndingCredit] = $this->splitSigned(
                bcadd(
                    $balance['opening_signed'],
                    bcsub($balance['period_debit'], $balance['period_credit'], 4),
                    4,
                ),
            );
            $openingDebit = bcadd($openingDebit, $accountOpeningDebit, 4);
            $openingCredit = bcadd($openingCredit, $accountOpeningCredit, 4);
            $periodDebit = bcadd($periodDebit, $balance['period_debit'], 4);
            $periodCredit = bcadd($periodCredit, $balance['period_credit'], 4);
            $endingDebit = bcadd($endingDebit, $accountEndingDebit, 4);
            $endingCredit = bcadd($endingCredit, $accountEndingCredit, 4);
        }

        return [
            'opening_debit' => $openingDebit,
            'opening_credit' => $openingCredit,
            'period_debit' => $periodDebit,
            'period_credit' => $periodCredit,
            'ending_debit' => $endingDebit,
            'ending_credit' => $endingCredit,
        ];
    }

    /** @return array{opening_signed: string, period_debit: string, period_credit: string} */
    private function emptyBalance(): array
    {
        return [
            'opening_signed' => '0.0000',
            'period_debit' => '0.0000',
            'period_credit' => '0.0000',
        ];
    }

    /** @param array{opening_signed: string, period_debit: string, period_credit: string} $balance */
    private function hasActivity(array $balance): bool
    {
        return bccomp($balance['opening_signed'], '0', 4) !== 0
            || bccomp($balance['period_debit'], '0', 4) !== 0
            || bccomp($balance['period_credit'], '0', 4) !== 0;
    }

    /** @return array{0: string, 1: string} */
    private function splitSigned(string $amount): array
    {
        return bccomp($amount, '0', 4) >= 0
            ? [$amount, '0.0000']
            : ['0.0000', bcmul($amount, '-1', 4)];
    }

    private function baseAmount(mixed $amount, mixed $exchangeRate): string
    {
        $normalizedAmount = $this->numbers->normalizeToScale($amount, 4) ?? '0.0000';
        $rate = $this->numbers->normalizeToScale($exchangeRate, 6) ?? '1.000000';

        return bcmul($normalizedAmount, $rate, 4);
    }
}
