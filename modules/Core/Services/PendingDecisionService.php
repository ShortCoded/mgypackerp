<?php

namespace Modules\Core\Services;

use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

final class PendingDecisionService
{
    public function __construct(
        private readonly DateFormatService $dates,
        private readonly EffectivePermissionResolver $permissions,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{count: int, items: Collection<int, array<string, mixed>>, meta: string, has_sources: bool}
     */
    public function summary(User $user, array $context, int $limit = 16): array
    {
        $sources = $this->eligibleSources($user);
        $union = $this->unionQuery($sources, $context);

        if (! $union instanceof Builder) {
            return [
                'count' => 0,
                'items' => collect(),
                'meta' => __('dashboard.personal.meta.approvals'),
                'has_sources' => false,
            ];
        }

        $records = DB::query()
            ->fromSub($union, 'pending_decisions')
            ->select(['source_index', 'record_id', 'document_number', 'route_value', 'requester_user_id', 'status', 'created_at'])
            ->selectRaw('COUNT(*) OVER () as total_count')
            ->selectRaw('MIN(created_at) OVER () as oldest_at')
            ->oldest('created_at')
            ->limit($limit)
            ->get();
        $first = $records->first();

        return [
            'count' => (int) ($first?->total_count ?? 0),
            'items' => $this->rows($records, $sources)->map(fn (array $row): array => [
                'key' => $row['key'],
                'title' => $row['type'],
                'body' => __('dashboard.personal.work.document', ['document' => $row['document_number']]),
                'meta' => __('dashboard.personal.work.waiting_since', ['time' => Carbon::parse($row['created_at'])->diffForHumans()]),
                'severity' => 'action',
                'url' => $row['url'],
                'rank' => 1,
                'sort_at' => $row['created_at'],
            ]),
            'meta' => $first?->oldest_at
                ? __('dashboard.personal.meta.approvals_oldest', ['time' => Carbon::parse($first->oldest_at)->diffForHumans()])
                : __('dashboard.personal.meta.approvals'),
            'has_sources' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $user, array $context, int $perPage = 30): LengthAwarePaginator
    {
        $sources = $this->eligibleSources($user);
        $union = $this->unionQuery($sources, $context);
        $query = $union instanceof Builder
            ? DB::query()->fromSub($union, 'pending_decisions')
            : DB::query()->from('users')->whereRaw('1 = 0')->selectRaw('0 as source_index, 0 as record_id, NULL as document_number, NULL as route_value, NULL as requester_user_id, NULL as status, NULL as created_at');
        $paginator = $query
            ->oldest('created_at')
            ->orderBy('record_id')
            ->paginate(max(1, min($perPage, 100)))
            ->withQueryString();

        $paginator->setCollection($this->rows($paginator->getCollection(), $sources));

        return $paginator;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $sources
     * @param  array<string, mixed>  $context
     */
    private function unionQuery(Collection $sources, array $context): ?Builder
    {
        $queries = $sources->map(function (array $source, int $index) use ($context): Builder {
            $query = DB::table($source['table'])
                ->whereIn('status', $source['statuses'])
                ->whereNull('deleted_at');

            $this->applyContext($query, $source, $context);
            $documentCast = DB::connection()->getDriverName() === 'mysql' ? 'CHAR' : 'TEXT';
            $requester = $source['requester_column'] !== null
                ? DB::connection()->getQueryGrammar()->wrap($source['requester_column'])
                : 'NULL';
            $routeValue = $source['route_column'] === 'id'
                ? DB::connection()->getQueryGrammar()->wrap('id')
                : DB::connection()->getQueryGrammar()->wrap($source['route_column']);
            $document = DB::connection()->getQueryGrammar()->wrap($source['document_column']);

            return $query
                ->selectRaw('CAST(? AS INTEGER) as source_index', [$index])
                ->selectRaw("CAST(id AS {$documentCast}) as record_id")
                ->selectRaw("CAST({$document} AS {$documentCast}) as document_number")
                ->selectRaw("CAST({$routeValue} AS {$documentCast}) as route_value")
                ->selectRaw("CAST({$requester} AS INTEGER) as requester_user_id")
                ->addSelect(['status', 'created_at']);
        });

        if ($queries->isEmpty()) {
            return null;
        }

        $union = $queries->shift();
        $queries->each(fn (Builder $query): Builder => $union->unionAll($query));

        return $union;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $context
     */
    private function applyContext(Builder $query, array $source, array $context): void
    {
        foreach ([
            'company_scoped' => ['company_id', 'company_id'],
            'branch_scoped' => ['branch_id', 'branch_id'],
            'financial_period_scoped' => ['financial_period_id', 'financial_period_id'],
        ] as $flag => [$column, $contextKey]) {
            if (! $source[$flag]) {
                continue;
            }

            $contextId = $context[$contextKey] ?? null;

            if (! is_numeric($contextId)) {
                $query->whereRaw('1 = 0');

                continue;
            }

            $query->where($column, (int) $contextId);
        }
    }

    /**
     * @param  Collection<int, object>  $records
     * @param  Collection<int, array<string, mixed>>  $sources
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Collection $records, Collection $sources): Collection
    {
        $requesterIds = $records->pluck('requester_user_id')->filter()->unique()->all();
        $requesterNames = $requesterIds === []
            ? collect()
            : User::query()->whereIn('id', $requesterIds)->pluck('name', 'id');

        return $records->map(function (object $record) use ($requesterNames, $sources): array {
            $source = $sources->get((int) $record->source_index);
            $requesterName = is_numeric($record->requester_user_id)
                ? $requesterNames->get((int) $record->requester_user_id)
                : null;

            return [
                'key' => "{$source['table']}:{$record->record_id}:{$record->status}",
                'type' => $source['title'],
                'document_number' => (string) $record->document_number,
                'requester' => is_string($requesterName) ? $requesterName : __('dashboard.personal.decisions.requester_unavailable'),
                'status' => (string) $record->status,
                'status_label' => __("notifications.statuses.{$record->status}"),
                'action' => $source['action'],
                'created_at' => (string) $record->created_at,
                'created_at_label' => $this->dates->formatDateTime($record->created_at, ''),
                'url' => $this->sourceUrl($source, (string) $record->route_value, (string) $record->status, (string) $record->document_number),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function sourceUrl(array $source, string $routeValue, string $status, string $documentNumber): string
    {
        if (is_string($source['show_route']) && Route::has($source['show_route'])) {
            return route($source['show_route'], [$routeValue], false);
        }

        return route($source['index_route'], [
            'status' => $status,
            'search' => $documentNumber,
        ], false);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function eligibleSources(User $user): Collection
    {
        $permissionNames = $this->permissions->namesFor($user);

        return collect($this->sources())
            ->filter(fn (array $source): bool => isset(
                $permissionNames[$source['permission']],
                $permissionNames[$source['view_permission']],
            ))
            ->values();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sources(): array
    {
        return [
            $this->source('purchase_requisitions', 'purchases.purchase_requisition_approvals.approve', 'purchases.purchase_requisitions.view', ['pending_approval'], 'purchase_requisitions', 'approve', 'admin.purchases.purchase-requisitions.index', 'admin.purchases.purchase-requisitions.show', 'doc_num', 'doc_num', 'created_by'),
            $this->source('purchase_orders', 'purchase_orders.approve', 'purchase_orders.view', ['submitted'], 'purchase_orders', 'approve', 'admin.purchases.purchase-orders.index', 'admin.purchases.purchase-orders.show', 'doc_num', 'doc_num', 'created_by'),
            $this->source('production_material_requests', 'production.material_requests.approve', 'production.material_requests.view', ['submitted'], 'material_requests', 'approve', 'admin.production.material-requests.index', 'admin.production.material-requests.show', 'doc_num', 'doc_num', 'created_by'),
            $this->source('quality_inspections', 'production.quality.review', 'production.quality.view', ['submitted'], 'quality', 'review', 'admin.production.quality.index', 'admin.production.quality.show', 'doc_num', 'id', 'requested_by'),
            $this->source('production_expense_requests', 'production.expenses.approve', 'production.expenses.view', ['submitted'], 'expenses', 'approve', 'admin.production.expenses.index', 'admin.production.expenses.show', 'doc_num', 'doc_num', 'created_by'),
            $this->source('production_expense_requests', 'production.expenses.pay', 'production.expenses.view', ['approved'], 'expenses_payment', 'pay', 'admin.production.expenses.index', 'admin.production.expenses.show', 'doc_num', 'doc_num', 'created_by'),
            $this->source('maintenance_work_orders', 'maintenance.orders.approve', 'maintenance.orders.view', ['draft'], 'maintenance', 'approve', 'admin.maintenance.orders.index', 'admin.maintenance.orders.show', 'doc_num', 'doc_num', 'created_by'),
            $this->source('sales_orders', 'sales_orders.approve', 'sales_orders.view', ['pending_approval'], 'sales_orders', 'approve', 'admin.sales.sales-orders.index', 'admin.sales.sales-orders.show', 'doc_num', 'doc_num', 'created_by'),
            $this->source('sales_orders', 'sales_orders.credit_override', 'sales_orders.view', ['held_credit'], 'credit_holds', 'credit_override', 'admin.sales.sales-orders.index', 'admin.sales.sales-orders.show', 'doc_num', 'doc_num', 'created_by'),
            $this->source('sales_returns', 'sales_returns.authorize', 'sales_returns.view', ['pending_authorization'], 'sales_returns', 'authorize', 'admin.sales.sales-returns.index', 'admin.sales.sales-returns.show', 'doc_num', 'doc_num', 'created_by'),
            $this->source('sales_returns', 'sales_returns.receive', 'sales_returns.view', ['authorized'], 'return_receipts', 'receive', 'admin.sales.sales-returns.index', 'admin.sales.sales-returns.show', 'doc_num', 'doc_num', 'created_by'),
            $this->source('sales_returns', 'sales_returns.inspect', 'sales_returns.view', ['received'], 'return_inspections', 'inspect', 'admin.sales.sales-returns.index', 'admin.sales.sales-returns.show', 'doc_num', 'doc_num', 'created_by'),
            $this->source('hr_employee_service_requests', 'hr.hr_requests.manage', 'hr.hr_requests.view', ['submitted'], 'hr_requests', 'manage', 'admin.hr.hr-requests.index', null, 'public_uuid', 'public_uuid', 'created_by', financialPeriodScoped: false),
        ];
    }

    /**
     * @param  list<string>  $statuses
     * @return array<string, mixed>
     */
    private function source(
        string $table,
        string $permission,
        string $viewPermission,
        array $statuses,
        string $titleKey,
        string $actionKey,
        string $indexRoute,
        ?string $showRoute,
        string $documentColumn,
        string $routeColumn,
        ?string $requesterColumn,
        bool $financialPeriodScoped = true,
    ): array {
        return [
            'table' => $table,
            'permission' => $permission,
            'view_permission' => $viewPermission,
            'statuses' => $statuses,
            'title' => __("dashboard.personal.sources.{$titleKey}"),
            'action' => __("dashboard.personal.actions.{$actionKey}"),
            'index_route' => $indexRoute,
            'show_route' => $showRoute,
            'document_column' => $documentColumn,
            'route_column' => $routeColumn,
            'requester_column' => $requesterColumn,
            'company_scoped' => true,
            'branch_scoped' => true,
            'financial_period_scoped' => $financialPeriodScoped,
        ];
    }
}
