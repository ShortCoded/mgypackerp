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
    public const ValueTotals = 'totals';

    public const ValueBalances = 'balances';

    public const ValueCombined = 'combined';

    public const TotalsPeriod = 'period';

    public const TotalsCumulative = 'cumulative';

    public const DisplayAggregate = 'aggregate';

    public const DisplayDetail = 'detail';

    public const DisplayTree = 'tree';

    public function __construct(private readonly NumericFormatService $numbers) {}

    /**
     * @param  array{
     *     company_id: int,
     *     from_date: string,
     *     to_date: string,
     *     branch_id?: int|null,
     *     cost_center_id?: int|null,
     *     include_zero?: bool,
     *     value_mode?: string,
     *     totals_basis?: string,
     *     display_mode?: string,
     *     level?: int|null
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

        $valueMode = $this->validOption(
            $filters['value_mode'] ?? null,
            [self::ValueTotals, self::ValueBalances, self::ValueCombined],
            self::ValueCombined,
        );
        $totalsBasis = $this->validOption(
            $filters['totals_basis'] ?? null,
            [self::TotalsPeriod, self::TotalsCumulative],
            self::TotalsPeriod,
        );
        $displayMode = $this->validOption(
            $filters['display_mode'] ?? null,
            [self::DisplayAggregate, self::DisplayDetail, self::DisplayTree],
            self::DisplayTree,
        );
        $maximumLevel = max(1, (int) ($accounts->max('level') ?? 1));
        $selectedLevel = min($maximumLevel, max(1, (int) ($filters['level'] ?? $maximumLevel)));
        $filters = [
            ...$filters,
            'value_mode' => $valueMode,
            'totals_basis' => $totalsBasis,
            'display_mode' => $displayMode,
            'level' => $selectedLevel,
        ];

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
            ->map(function (Account $account) use ($aggregates, $children, $direct, $displayMode): array {
                $accountId = (int) $account->getKey();
                $balance = $displayMode === self::DisplayDetail
                    ? ($direct[$accountId] ?? $this->emptyBalance())
                    : ($aggregates[$accountId] ?? $this->emptyBalance());

                return [
                    'id' => $accountId,
                    'parent_id' => $account->parent_id ? (int) $account->parent_id : null,
                    'doc_num' => (string) $account->doc_num,
                    'account_code' => (string) $account->account_code,
                    'name' => $account->displayName(),
                    'level' => (int) $account->level,
                    'is_group' => (bool) $account->is_group,
                    'has_children' => ($children[$accountId] ?? []) !== [],
                    'is_postable' => (bool) $account->is_postable,
                    'is_inactive' => $account->status !== 'active' || $account->trashed(),
                    ...$balance,
                    'has_direct_activity' => isset($direct[$accountId]) && $this->hasAnyActivity($direct[$accountId]),
                ];
            })
            ->when(
                $displayMode === self::DisplayAggregate,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['level'] === $selectedLevel
                        || ($row['level'] < $selectedLevel && ! $row['has_children'])
                ),
            )
            ->when(
                $displayMode === self::DisplayDetail,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['has_direct_activity'] || ($includeZero && $row['is_postable'])
                ),
            )
            ->when(
                ! $includeZero,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $this->hasVisibleActivity($row, $valueMode, $totalsBasis)
                ),
            )
            ->values()
            ->all();

        $totals = $this->totals($direct);
        $scopeIsPartial = ($filters['branch_id'] ?? null) !== null || ($filters['cost_center_id'] ?? null) !== null;
        $isBalanced = bccomp($totals['opening_debit'], $totals['opening_credit'], 4) === 0
            && bccomp($totals['period_debit'], $totals['period_credit'], 4) === 0
            && bccomp($totals['cumulative_debit'], $totals['cumulative_credit'], 4) === 0
            && bccomp($totals['ending_debit'], $totals['ending_credit'], 4) === 0;

        return [
            'currency' => Currency::query()
                ->forCompany($filters['company_id'])
                ->active()
                ->where('is_main', true)
                ->first(['doc_num', 'code', 'name'])?->only(['doc_num', 'code', 'name']),
            'filters' => $filters,
            'presentation' => [
                'value_mode' => $valueMode,
                'totals_basis' => $totalsBasis,
                'display_mode' => $displayMode,
                'level' => $selectedLevel,
                'maximum_level' => $maximumLevel,
                'columns' => $this->columns($valueMode, $totalsBasis),
            ],
            'rows' => $rows,
            'totals' => $totals,
            'scope_is_partial' => $scopeIsPartial,
            'is_balanced' => $isBalanced,
            'balance_status' => $scopeIsPartial ? 'partial_scope' : ($isBalanced ? 'balanced' : 'unbalanced'),
            'generated_at' => now(),
            'generated_by' => auth()->user()?->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $accountIds
     * @return array<int, array<string, string>>
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
            $balances[$accountId]['cumulative_debit'] = bcadd($balances[$accountId]['cumulative_debit'], $debit, 4);
            $balances[$accountId]['cumulative_credit'] = bcadd($balances[$accountId]['cumulative_credit'], $credit, 4);

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

        foreach ($balances as $accountId => $balance) {
            $balances[$accountId] = $this->withBalanceSides($balance);
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
     * @param  array<int, array<string, string>>  $direct
     * @param  array<int, array<string, string>>  $aggregates
     * @param  array<int, bool>  $visiting
     * @return array<string, string>
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

            foreach ($this->summableColumns() as $column) {
                $balance[$column] = bcadd($balance[$column], $child[$column], 4);
            }
        }

        unset($visiting[$accountId]);

        return $aggregates[$accountId] = $this->withNetSides($balance);
    }

    /**
     * @param  array<int, array<string, string>>  $direct
     * @return array<string, string>
     */
    private function totals(array $direct): array
    {
        $totals = $this->emptyBalance();

        foreach ($direct as $balance) {
            foreach ($this->summableColumns() as $column) {
                $totals[$column] = bcadd($totals[$column], $balance[$column], 4);
            }
        }

        return collect($this->summableColumns())
            ->mapWithKeys(fn (string $column): array => [$column => $totals[$column]])
            ->all();
    }

    /** @return array<string, string> */
    private function emptyBalance(): array
    {
        return [
            'opening_signed' => '0.0000',
            'opening_debit' => '0.0000',
            'opening_credit' => '0.0000',
            'period_debit' => '0.0000',
            'period_credit' => '0.0000',
            'cumulative_debit' => '0.0000',
            'cumulative_credit' => '0.0000',
            'ending_debit' => '0.0000',
            'ending_credit' => '0.0000',
            'net_opening_debit' => '0.0000',
            'net_opening_credit' => '0.0000',
            'net_ending_debit' => '0.0000',
            'net_ending_credit' => '0.0000',
        ];
    }

    /** @param array<string, string> $balance */
    private function withBalanceSides(array $balance): array
    {
        [$balance['opening_debit'], $balance['opening_credit']] = $this->splitSigned($balance['opening_signed']);
        [$balance['ending_debit'], $balance['ending_credit']] = $this->splitSigned(
            bcadd(
                $balance['opening_signed'],
                bcsub($balance['period_debit'], $balance['period_credit'], 4),
                4,
            ),
        );

        return $this->withNetSides($balance);
    }

    /** @param array<string, string> $balance */
    private function withNetSides(array $balance): array
    {
        [$balance['net_opening_debit'], $balance['net_opening_credit']] = $this->splitSigned(
            bcsub($balance['opening_debit'], $balance['opening_credit'], 4),
        );
        [$balance['net_ending_debit'], $balance['net_ending_credit']] = $this->splitSigned(
            bcsub($balance['ending_debit'], $balance['ending_credit'], 4),
        );

        return $balance;
    }

    /** @return list<string> */
    private function summableColumns(): array
    {
        return [
            'opening_debit',
            'opening_credit',
            'period_debit',
            'period_credit',
            'cumulative_debit',
            'cumulative_credit',
            'ending_debit',
            'ending_credit',
        ];
    }

    /** @param array<string, string> $balance */
    private function hasAnyActivity(array $balance): bool
    {
        return collect($this->summableColumns())
            ->contains(fn (string $column): bool => bccomp($balance[$column], '0', 4) !== 0);
    }

    /** @param array<string, mixed> $row */
    private function hasVisibleActivity(array $row, string $valueMode, string $totalsBasis): bool
    {
        return collect($this->columns($valueMode, $totalsBasis))
            ->contains(fn (string $column): bool => bccomp((string) $row[$column], '0', 4) !== 0);
    }

    /** @return list<string> */
    private function columns(string $valueMode, string $totalsBasis): array
    {
        $totalsColumns = $totalsBasis === self::TotalsCumulative
            ? ['cumulative_debit', 'cumulative_credit']
            : ['period_debit', 'period_credit'];

        return match ($valueMode) {
            self::ValueTotals => $totalsColumns,
            self::ValueBalances => ['opening_debit', 'opening_credit', 'ending_debit', 'ending_credit'],
            default => [
                'opening_debit',
                'opening_credit',
                'period_debit',
                'period_credit',
                ...($totalsBasis === self::TotalsCumulative ? $totalsColumns : []),
                'ending_debit',
                'ending_credit',
            ],
        };
    }

    /** @param list<string> $allowed */
    private function validOption(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
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
