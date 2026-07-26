<?php

namespace Modules\Core\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Enums\ScreenDataVisibilityDurationUnit;
use Modules\Auth\Enums\ScreenDataVisibilityRecordScope;
use Modules\Auth\Models\ScreenDataVisibilityRule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ScreenDataVisibilityService
{
    public function __construct(
        private readonly ScreenDataVisibilityRegistry $registry,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingContextService $operatingContext,
        private readonly RequestMemo $memo,
    ) {}

    public function ruleFor(User $user, string $screenKey): ?ScreenDataVisibilityRule
    {
        if ($user->can('screen_data_visibility_rules.bypass') || ! $this->registry->isSupported($screenKey)) {
            return null;
        }

        $companyId = $this->companies->currentCompanyId();
        if ($companyId === null || ! $this->rulesTableExists()) {
            return null;
        }

        return $this->memo->remember(
            "screen_visibility.rule.{$companyId}.{$user->getKey()}.{$screenKey}",
            fn (): ?ScreenDataVisibilityRule => ScreenDataVisibilityRule::query()
                ->forCompany($companyId)
                ->where('user_id', $user->getKey())
                ->where('screen_key', $screenKey)
                ->where('is_active', true)
                ->first(),
        );
    }

    /** @template TModel of Model @param Builder<TModel> $query @return Builder<TModel> */
    public function applyToEloquent(Builder $query, User $user, string $screenKey, bool $preserveBaseQuery = true): Builder
    {
        $rule = $this->ruleFor($user, $screenKey);
        if (! $rule || $this->alreadyApplied($query->getQuery(), $screenKey)) {
            return $query;
        }

        $definition = $this->registry->definition($screenKey);
        if (! is_array($definition) || ! $definition['supported']) {
            return $query;
        }

        $this->applyRule(
            $query->getQuery(),
            $rule,
            $screenKey,
            $definition,
            $preserveBaseQuery ? clone $query->getQuery() : null,
        );

        return $query;
    }

    public function applyToQuery(QueryBuilder $query, User $user, string $screenKey, bool $preserveBaseQuery = true): QueryBuilder
    {
        $rule = $this->ruleFor($user, $screenKey);
        if (! $rule || $this->alreadyApplied($query, $screenKey)) {
            return $query;
        }

        $definition = $this->registry->definition($screenKey);
        if (! is_array($definition) || ! $definition['supported']) {
            return $query;
        }

        $this->applyRule($query, $rule, $screenKey, $definition, $preserveBaseQuery ? clone $query : null);

        return $query;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $screenKeys
     * @return Builder<TModel>
     */
    public function applyAnyScreenToEloquent(Builder $query, User $user, array $screenKeys): Builder
    {
        $allowedQueries = [];
        $modelClass = $query->getModel()::class;

        foreach (array_values(array_unique($screenKeys)) as $screenKey) {
            $definition = $this->registry->definition($screenKey);
            if (! is_array($definition) || ! $definition['supported'] || $definition['model'] !== $modelClass) {
                continue;
            }

            $allowed = clone $query;
            $base = $allowed->getQuery();
            $base->columns = null;
            $base->orders = null;
            $base->limit = null;
            $base->offset = null;

            $this->applyConfiguredBaseScope($base, $definition);
            $this->applyToEloquent($allowed, $user, $screenKey);
            $allowedQueries[] = $allowed->select("{$definition['table']}.{$definition['primary_column']}");
        }

        if ($allowedQueries === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $visible) use ($allowedQueries): void {
            foreach ($allowedQueries as $index => $allowed) {
                $method = $index === 0 ? 'whereIn' : 'orWhereIn';
                $visible->{$method}($visible->getModel()->qualifyColumn($visible->getModel()->getKeyName()), $allowed);
            }
        });
    }

    public function canAccessRecord(Model $record, User $user, string $screenKey): bool
    {
        $definition = $this->registry->definition($screenKey);
        if (! is_array($definition) || ! $definition['supported'] || ! $this->ruleFor($user, $screenKey)) {
            return true;
        }

        $query = DB::table($definition['table']);
        $this->applyToQuery($query, $user, $screenKey, false);

        return $query->where($definition['primary_column'], $record->getKey())->exists();
    }

    public function assertCanAccessRecord(Model $record, User $user, string $screenKey): void
    {
        if (! $this->canAccessRecord($record, $user, $screenKey)) {
            throw new NotFoundHttpException;
        }
    }

    /** @return list<int|string> */
    public function limitedAuthorizedRecordIds(User $user, string $screenKey): array
    {
        $definition = $this->registry->definition($screenKey);
        if (! is_array($definition) || ! $definition['supported']) {
            return [];
        }

        $query = DB::table($definition['table']);
        if ($this->ruleFor($user, $screenKey)) {
            $this->applyToQuery($query, $user, $screenKey, false);
        } else {
            $this->applyConfiguredBaseScope($query, $definition);
        }

        return $query->pluck($definition['primary_column'])->all();
    }

    public function effectiveSummary(User $user, string $screenKey): string
    {
        $rule = $this->ruleFor($user, $screenKey);
        if (! $rule) {
            return __('screen_data_visibility_rules.preview.unrestricted');
        }

        return __('screen_data_visibility_rules.preview.summary', [
            'scope' => __('screen_data_visibility_rules.scopes.'.$rule->record_scope->value),
            'maximum' => $rule->max_visible_records ?? __('screen_data_visibility_rules.preview.no_maximum'),
            'duration' => $rule->duration_value
                ? $rule->duration_value.' '.__('screen_data_visibility_rules.duration_units.'.$rule->duration_unit?->value)
                : __('screen_data_visibility_rules.preview.no_duration'),
        ]);
    }

    public function forgetRule(ScreenDataVisibilityRule $rule): void
    {
        $this->memo->forget("screen_visibility.rule.{$rule->company_id}.{$rule->user_id}.{$rule->screen_key}");
    }

    /** @param array<string, mixed> $definition */
    private function applyRule(QueryBuilder $outer, ScreenDataVisibilityRule $rule, string $screenKey, array $definition, ?QueryBuilder $base): void
    {
        $table = $definition['table'];
        $primary = $definition['primary_column'];
        $qualifiedPrimary = "{$table}.{$primary}";
        $marker = $this->marker($screenKey);
        $outer->whereRaw("1 = 1 /* {$marker} */");

        $this->applyConfiguredBaseScope($outer, $definition);
        $this->applyOwnershipAndDuration($outer, $rule, $definition, true);

        if ($rule->max_visible_records === null) {
            return;
        }

        $allowed = $base ?? DB::table($table);
        $allowed->columns = null;
        $allowed->orders = null;
        $allowed->limit = null;
        $allowed->offset = null;

        $this->applyConfiguredBaseScope($allowed, $definition);

        $this->applyOwnershipAndDuration($allowed, $rule, $definition, $base !== null);
        $allowed
            ->select($qualifiedPrimary)
            ->orderByDesc("{$table}.{$definition['date_column']}")
            ->orderByDesc($qualifiedPrimary)
            ->limit($rule->max_visible_records);

        $outer->whereIn($qualifiedPrimary, $allowed);
    }

    /** @param array<string, mixed> $definition */
    private function applyOwnershipAndDuration(QueryBuilder $query, ScreenDataVisibilityRule $rule, array $definition, bool $qualified): void
    {
        $prefix = $qualified ? $definition['table'].'.' : '';

        if ($rule->record_scope === ScreenDataVisibilityRecordScope::OwnRecords) {
            $query->where($prefix.$definition['ownership_column'], $rule->user_id);
        }

        if ($rule->duration_value !== null && $rule->duration_unit instanceof ScreenDataVisibilityDurationUnit) {
            $query->where($prefix.$definition['date_column'], '>=', $this->cutoff($rule->duration_value, $rule->duration_unit));
        }
    }

    /** @param array<string, mixed> $definition */
    private function applyConfiguredBaseScope(QueryBuilder $query, array $definition): void
    {
        $table = $definition['table'];
        $snapshot = $this->operatingContext->snapshot(request());

        if ($definition['company_column'] !== null) {
            $companyId = $snapshot['company_id'];
            $companyId === null
                ? $query->whereRaw('1 = 0')
                : $query->where("{$table}.{$definition['company_column']}", $companyId);
        }

        if ($definition['branch_column'] !== null && $snapshot['branch_id'] !== null) {
            $query->where("{$table}.{$definition['branch_column']}", $snapshot['branch_id']);
        }

        if ($definition['financial_period_column'] !== null && $snapshot['financial_period_id'] !== null) {
            $query->where("{$table}.{$definition['financial_period_column']}", $snapshot['financial_period_id']);
        }

        foreach ($definition['conditions'] as $condition) {
            $column = "{$table}.{$condition['column']}";

            if ($condition['operator'] === 'not_equal_or_null') {
                $query->where(fn (QueryBuilder $nested): QueryBuilder => $nested->whereNull($column)->orWhere($column, '<>', $condition['value']));
            } else {
                $query->where($column, $condition['value']);
            }
        }

        $recordState = request()->string('record_state')->toString();
        $trashFilter = request()->string('trash_filter')->toString();
        if ($trashFilter === 'trashed' || $recordState === 'deleted') {
            $query->whereNotNull("{$table}.deleted_at");
        } elseif ($trashFilter !== 'all' && $recordState !== 'all' && ! request()->routeIs('*.show', '*.restore')) {
            $query->whereNull("{$table}.deleted_at");
        }
    }

    private function cutoff(int $value, ScreenDataVisibilityDurationUnit $unit): CarbonImmutable
    {
        $now = CarbonImmutable::now(config('app.timezone'));

        return match ($unit) {
            ScreenDataVisibilityDurationUnit::Days => $now->subDays($value),
            ScreenDataVisibilityDurationUnit::Weeks => $now->subWeeks($value),
            ScreenDataVisibilityDurationUnit::Months => $now->subMonthsNoOverflow($value),
            ScreenDataVisibilityDurationUnit::Years => $now->subYearsNoOverflow($value),
        };
    }

    private function rulesTableExists(): bool
    {
        return $this->memo->remember('screen_visibility.table_exists', fn (): bool => Schema::hasTable('screen_data_visibility_rules'));
    }

    private function alreadyApplied(QueryBuilder $query, string $screenKey): bool
    {
        $marker = $this->marker($screenKey);

        return collect($query->wheres ?? [])->contains(
            fn (array $where): bool => ($where['type'] ?? null) === 'raw' && str_contains((string) ($where['sql'] ?? ''), $marker),
        );
    }

    private function marker(string $screenKey): string
    {
        return 'screen-data-visibility:'.preg_replace('/[^a-z0-9_.-]/i', '', $screenKey);
    }
}
