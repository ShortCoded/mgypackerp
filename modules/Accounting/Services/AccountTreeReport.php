<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Services\OperatingCompanyContextService;

class AccountTreeReport
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
    ) {}

    /**
     * @return array<string, string>
     */
    public function filtersFromRequest(Request $request): array
    {
        $filters = [];

        foreach (['account_search', 'statement_type', 'normal_balance', 'classification', 'status', 'hierarchy', 'level'] as $field) {
            $value = trim((string) $request->input($field, ''));

            if ($value !== '') {
                $filters[$field] = $value;
            }
        }

        return $filters;
    }

    /**
     * @param  array<string, string>  $filters
     * @return Builder<Account>
     */
    public function query(array $filters = []): Builder
    {
        $companyId = $this->companies->currentCompanyId();
        $query = Account::query()
            ->with(['parent', 'classification']);

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->forCompany($companyId);

        $search = $filters['account_search'] ?? '';
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $like = '%'.mb_strtolower($search).'%';

                $builder->whereRaw('LOWER(accounts.account_code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(accounts.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(accounts.name_en, \'\')) LIKE ?', [$like])
                    ->orWhereHas('classification', function (Builder $classification) use ($like): void {
                        $classification->whereRaw('LOWER(account_classifications.code) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(account_classifications.name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(COALESCE(account_classifications.name_en, \'\')) LIKE ?', [$like]);
                    });
            });
        }

        foreach (['statement_type', 'normal_balance', 'status'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $query->where("accounts.{$field}", $filters[$field]);
            }
        }

        if (($filters['classification'] ?? '') !== '') {
            $query->whereHas('classification', fn (Builder $builder): Builder => $builder->where('code', $filters['classification']));
        }

        if (($filters['hierarchy'] ?? '') === 'root') {
            $query->whereNull('accounts.parent_id');
        } elseif (($filters['hierarchy'] ?? '') === 'children') {
            $query->whereNotNull('accounts.parent_id');
        }

        if (($filters['level'] ?? '') !== '' && ctype_digit($filters['level'])) {
            $query->where('accounts.level', (int) $filters['level']);
        }

        return $query;
    }

    /**
     * @param  array<string, string>  $filters
     * @return Collection<int, Account>
     */
    public function rows(array $filters = []): Collection
    {
        return $this->treeRows($filters);
    }

    /**
     * @param  array<string, string>  $filters
     * @return Collection<int, Account>
     */
    public function treeRows(array $filters = []): Collection
    {
        return $this->flattenTree($this->withAncestorContext($this->query($filters)->get()));
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array<int, array<string, mixed>>
     */
    public function treeNodes(Collection $accounts): array
    {
        $byParent = $accounts
            ->groupBy(fn (Account $account): int => (int) ($account->parent_id ?? 0))
            ->map(fn (Collection $siblings): Collection => $this->sortSiblings($siblings));
        $included = $accounts->keyBy(fn (Account $account): int => (int) $account->getKey());
        $rootAccounts = $this->sortSiblings(
            $accounts->filter(fn (Account $account): bool => $account->parent_id === null || ! $included->has((int) $account->parent_id))
        );

        return $this->nestedTree($rootAccounts, $byParent);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            __('accounts.attributes.level'),
            __('accounts.attributes.account_code'),
            __('accounts.attributes.name'),
            __('accounts.attributes.parent_code'),
            __('accounts.attributes.classification'),
            __('accounts.attributes.statement_type'),
            __('accounts.attributes.normal_balance'),
            __('accounts.attributes.status'),
        ];
    }

    /**
     * @return list<string>
     */
    public function pdfHeadings(): array
    {
        return [
            __('accounts.attributes.account_code'),
            __('accounts.attributes.name'),
            __('accounts.attributes.parent_code'),
            __('accounts.attributes.classification'),
            __('accounts.attributes.statement_type'),
            __('accounts.attributes.normal_balance'),
            __('accounts.attributes.status'),
        ];
    }

    /**
     * @return list<string>
     */
    public function map(Account $row): array
    {
        return [
            (string) $row->level,
            $row->account_code,
            str_repeat('  ', max(0, ((int) $row->level) - 1)).$row->name,
            $this->parentDisplay($row),
            $row->classification?->displayName() ?? '',
            __("accounts.statement_types.{$row->statement_type}"),
            __("accounts.normal_balances.{$row->normal_balance}"),
            __("accounts.statuses.{$row->status}"),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pdfRows(Collection $rows): array
    {
        return $rows
            ->map(fn (Account $row): array => [
                'account_code' => $row->account_code,
                'name' => $row->name,
                'level' => (int) $row->level,
                'is_group' => (bool) $row->is_group,
                'parent_code' => $this->parentDisplay($row),
                'classification' => $row->classification?->displayName() ?? '',
                'statement_type' => __("accounts.statement_types.{$row->statement_type}"),
                'normal_balance' => __("accounts.normal_balances.{$row->normal_balance}"),
                'status' => __("accounts.statuses.{$row->status}"),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    public function filterSummary(array $filters): array
    {
        $summary = [];

        foreach ($filters as $key => $value) {
            $labelKey = $key === 'account_search' ? 'search' : $key;
            $summary[] = __('accounts.filters.'.$labelKey).': '.$this->filterValueLabel($key, $value);
        }

        return $summary;
    }

    private function filterValueLabel(string $key, string $value): string
    {
        return match ($key) {
            'statement_type' => __("accounts.statement_types.{$value}"),
            'normal_balance' => __("accounts.normal_balances.{$value}"),
            'status' => __("accounts.statuses.{$value}"),
            'hierarchy' => __("accounts.hierarchy_filters.{$value}"),
            'classification' => AccountClassification::query()->where('code', $value)->first()?->displayName() ?? $value,
            default => $value,
        };
    }

    private function parentDisplay(Account $account): string
    {
        return $account->parent?->codeNameLabel() ?? '';
    }

    /**
     * @param  Collection<int, Account>  $matched
     * @return Collection<int, Account>
     */
    private function withAncestorContext(Collection $matched): Collection
    {
        if ($matched->isEmpty()) {
            return $matched;
        }

        $accounts = $matched->keyBy(fn (Account $account): int => (int) $account->getKey());
        $missingParentIds = $this->missingParentIds($accounts);

        while ($missingParentIds->isNotEmpty()) {
            $parents = Account::query()
                ->with(['parent', 'classification'])
                ->forCompany($this->companies->requireCompanyId())
                ->whereIn('id', $missingParentIds->all())
                ->get();

            if ($parents->isEmpty()) {
                break;
            }

            foreach ($parents as $parent) {
                $accounts->put((int) $parent->getKey(), $parent);
            }

            $missingParentIds = $this->missingParentIds($accounts);
        }

        return $accounts->values();
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return Collection<int, int>
     */
    private function missingParentIds(Collection $accounts): Collection
    {
        return $accounts
            ->pluck('parent_id')
            ->filter()
            ->map(fn (mixed $parentId): int => (int) $parentId)
            ->reject(fn (int $parentId): bool => $accounts->has($parentId))
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return Collection<int, Account>
     */
    private function flattenTree(Collection $accounts): Collection
    {
        $byParent = $accounts
            ->groupBy(fn (Account $account): int => (int) ($account->parent_id ?? 0))
            ->map(fn (Collection $siblings): Collection => $this->sortSiblings($siblings));
        $included = $accounts->keyBy(fn (Account $account): int => (int) $account->getKey());
        $roots = $this->sortSiblings(
            $accounts->filter(fn (Account $account): bool => $account->parent_id === null || ! $included->has((int) $account->parent_id))
        );
        $flattened = collect();

        foreach ($roots as $root) {
            $this->appendTreeRows($root, $byParent, $flattened);
        }

        return $flattened->values();
    }

    /**
     * @param  Collection<int, Collection<int, Account>>  $byParent
     * @param  Collection<int, Account>  $flattened
     */
    private function appendTreeRows(Account $account, Collection $byParent, Collection $flattened): void
    {
        $flattened->push($account);

        foreach ($byParent->get((int) $account->getKey(), collect()) as $child) {
            $this->appendTreeRows($child, $byParent, $flattened);
        }
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return Collection<int, Account>
     */
    private function sortSiblings(Collection $accounts): Collection
    {
        return $accounts
            ->sort(function (Account $first, Account $second): int {
                $codeComparison = strnatcmp($first->account_code, $second->account_code);

                if ($codeComparison !== 0) {
                    return $codeComparison;
                }

                return strcmp($first->name, $second->name);
            })
            ->values();
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  Collection<int, Collection<int, Account>>  $byParent
     * @return array<int, array<string, mixed>>
     */
    private function nestedTree(Collection $accounts, Collection $byParent): array
    {
        return $accounts
            ->map(fn (Account $account): array => [
                'id' => $account->doc_num,
                'account_code' => $account->account_code,
                'name' => $account->name,
                'name_en' => $account->name_en,
                'level' => (int) $account->level,
                'statement_type' => __("accounts.statement_types.{$account->statement_type}"),
                'normal_balance' => __("accounts.normal_balances.{$account->normal_balance}"),
                'classification' => $account->classification?->displayName(),
                'is_group' => (bool) $account->is_group,
                'is_postable' => (bool) $account->is_postable,
                'status' => __("accounts.statuses.{$account->status}"),
                'children' => $this->nestedTree($byParent->get((int) $account->getKey(), collect()), $byParent),
            ])
            ->values()
            ->all();
    }
}
