<?php

namespace Modules\Maintenance\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingContextService;
use Modules\Maintenance\Models\MaintenanceMaterialRequest;
use Modules\Maintenance\Models\MaintenanceRequest;
use Modules\Maintenance\Models\MaintenanceWorkOrder;
use Modules\Production\Models\ProductionExpenseRequest;
use Yajra\DataTables\Facades\DataTables;

class MaintenanceDataTable
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly DataTableSearchService $search,
    ) {}

    public function requests(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = $this->requestTrashQuery($request)
            ->when($this->hasContext($context), fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->leftJoin('fixed_assets', 'fixed_assets.id', '=', 'maintenance_requests.fixed_asset_id')
            ->leftJoin('production_molds', 'production_molds.id', '=', 'maintenance_requests.production_mold_id')
            ->leftJoin('maintenance_work_orders', function ($join): void {
                $join->on('maintenance_work_orders.maintenance_request_id', '=', 'maintenance_requests.id')
                    ->whereNull('maintenance_work_orders.deleted_at');
            })
            ->select(['maintenance_requests.*', 'fixed_assets.asset_name', 'production_molds.name as mold_name', 'maintenance_work_orders.doc_num as work_order_number']);

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['maintenance_requests.doc_num', 'fixed_assets.asset_name', 'production_molds.name', 'maintenance_requests.symptoms', 'maintenance_requests.status']))
            ->addColumn('select', fn (MaintenanceRequest $row): string => $this->selection($request, $row->doc_num, ! $row->trashed() && $row->status === MaintenanceRequest::StatusOpen && blank($row->work_order_number), 'maintenance.requests.delete'))
            ->editColumn('doc_num', fn (MaintenanceRequest $row): string => $row->trashed() ? e($row->doc_num) : '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.maintenance.requests.show', $row)).'">'.e($row->doc_num).'</a>')
            ->editColumn('reported_at', fn (MaintenanceRequest $row): string => e($row->reported_at?->format('Y-m-d H:i') ?? ''))
            ->editColumn('request_type', fn (MaintenanceRequest $row): string => e(__('maintenance.request_types.'.$row->request_type)))
            ->editColumn('priority', fn (MaintenanceRequest $row): string => e(__('maintenance.priorities.'.$row->priority)))
            ->editColumn('status', fn (MaintenanceRequest $row): string => $this->badge(__('maintenance.statuses.'.$row->status)))
            ->addColumn('maintainable', fn (MaintenanceRequest $row): string => e($row->asset_name ?: $row->mold_name ?: '—'))
            ->addColumn('actions', fn (MaintenanceRequest $row): string => $this->requestActions($request, $row))
            ->orderColumn('maintainable', 'coalesce(fixed_assets.asset_name, production_molds.name) $1')
            ->orderColumn('work_order_number', 'maintenance_work_orders.doc_num $1')
            ->rawColumns(['select', 'doc_num', 'status', 'actions'])->removeColumn('id')->removeColumn('company_id')->toJson();
    }

    public function orders(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = $this->trashQuery($request, MaintenanceWorkOrder::class, 'maintenance.orders.view_trashed')
            ->when($this->hasContext($context), fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->leftJoin('fixed_assets', 'fixed_assets.id', '=', 'maintenance_work_orders.fixed_asset_id')
            ->leftJoin('production_molds', 'production_molds.id', '=', 'maintenance_work_orders.production_mold_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'maintenance_work_orders.supplier_id')
            ->select(['maintenance_work_orders.*', 'fixed_assets.asset_name', 'production_molds.name as mold_name', 'suppliers.name as supplier_name'])
            ->withExists(['materialRequests', 'expenses', 'events']);

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['maintenance_work_orders.doc_num', 'fixed_assets.asset_name', 'production_molds.name', 'maintenance_work_orders.work_description', 'suppliers.name', 'maintenance_work_orders.status']))
            ->addColumn('select', fn (MaintenanceWorkOrder $row): string => $this->selection($request, $row->doc_num, ! $row->trashed() && $row->status === MaintenanceWorkOrder::StatusDraft && ! $row->material_requests_exists && ! $row->expenses_exists && ! $row->events_exists, 'maintenance.orders.delete'))
            ->editColumn('doc_num', fn (MaintenanceWorkOrder $row): string => $row->trashed() ? e($row->doc_num) : '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.maintenance.orders.show', $row)).'">'.e($row->doc_num).'</a>')
            ->editColumn('planned_start_at', fn (MaintenanceWorkOrder $row): string => e($row->planned_start_at?->format('Y-m-d H:i') ?? '—'))
            ->editColumn('maintenance_type', fn (MaintenanceWorkOrder $row): string => e(__('maintenance.maintenance_types.'.$row->maintenance_type)))
            ->editColumn('service_mode', fn (MaintenanceWorkOrder $row): string => e(__('maintenance.service_modes.'.$row->service_mode)))
            ->editColumn('priority', fn (MaintenanceWorkOrder $row): string => e(__('maintenance.priorities.'.$row->priority)))
            ->editColumn('status', fn (MaintenanceWorkOrder $row): string => $this->badge(__('maintenance.statuses.'.$row->status)))
            ->addColumn('provider', fn (MaintenanceWorkOrder $row): string => e($row->supplier_name ?: $row->external_provider_name ?: __('maintenance.internal')))
            ->addColumn('actions', fn (MaintenanceWorkOrder $row): string => $this->actions($request, $row))
            ->orderColumn('asset_name', 'fixed_assets.asset_name $1')
            ->orderColumn('mold_name', 'production_molds.name $1')
            ->rawColumns(['select', 'doc_num', 'status', 'actions'])->removeColumn('id')->removeColumn('company_id')->toJson();
    }

    public function materialRequests(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = $this->trashQuery($request, MaintenanceMaterialRequest::class, 'maintenance.material_requests.view_trashed')
            ->when($this->hasContext($context), fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->join('maintenance_work_orders', 'maintenance_work_orders.id', '=', 'maintenance_material_requests.maintenance_work_order_id')
            ->leftJoin('fixed_assets', 'fixed_assets.id', '=', 'maintenance_work_orders.fixed_asset_id')
            ->join('branch_stores', 'branch_stores.id', '=', 'maintenance_material_requests.branch_store_id')
            ->leftJoin('inventory_documents as issue_documents', 'issue_documents.id', '=', 'maintenance_material_requests.inventory_issue_document_id')
            ->leftJoin('inventory_documents as return_documents', 'return_documents.id', '=', 'maintenance_material_requests.inventory_return_document_id')
            ->select(['maintenance_material_requests.*', 'maintenance_work_orders.doc_num as work_order_number', 'fixed_assets.asset_name', 'branch_stores.name as store_name', 'issue_documents.doc_num as issue_number', 'return_documents.doc_num as return_number'])
            ->withCount('lines');

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['maintenance_material_requests.doc_num', 'maintenance_work_orders.doc_num', 'fixed_assets.asset_name', 'branch_stores.name', 'maintenance_material_requests.reason', 'maintenance_material_requests.status']))
            ->addColumn('select', fn (MaintenanceMaterialRequest $row): string => $this->selection($request, $row->doc_num, ! $row->trashed() && $row->status === MaintenanceMaterialRequest::StatusSubmitted && $row->inventory_issue_document_id === null, 'maintenance.material_requests.delete'))
            ->editColumn('doc_num', fn (MaintenanceMaterialRequest $row): string => $row->trashed() ? e($row->doc_num) : '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.maintenance.material-requests.show', $row)).'">'.e($row->doc_num).'</a>')
            ->editColumn('request_date', fn (MaintenanceMaterialRequest $row): string => e($row->request_date?->format('Y-m-d') ?? ''))
            ->editColumn('status', fn (MaintenanceMaterialRequest $row): string => $this->badge(__('maintenance.statuses.'.$row->status)))
            ->addColumn('actions', fn (MaintenanceMaterialRequest $row): string => $this->materialActions($request, $row))
            ->orderColumn('work_order_number', 'maintenance_work_orders.doc_num $1')
            ->orderColumn('asset_name', 'fixed_assets.asset_name $1')
            ->orderColumn('store_name', 'branch_stores.name $1')
            ->rawColumns(['select', 'doc_num', 'status', 'actions'])->removeColumn('id')->removeColumn('company_id')->toJson();
    }

    public function expenses(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = $this->trashQuery($request, ProductionExpenseRequest::class, 'maintenance.expenses.view_trashed')
            ->when($this->hasContext($context), fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereNotNull('production_expense_requests.maintenance_work_order_id')
            ->join('maintenance_work_orders', 'maintenance_work_orders.id', '=', 'production_expense_requests.maintenance_work_order_id')
            ->leftJoin('fixed_assets', 'fixed_assets.id', '=', 'maintenance_work_orders.fixed_asset_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'production_expense_requests.currency_id')
            ->leftJoin('cash_vouchers', 'cash_vouchers.id', '=', 'production_expense_requests.cash_voucher_id')
            ->select(['production_expense_requests.*', 'maintenance_work_orders.doc_num as work_order_number', 'fixed_assets.asset_name', 'currencies.code as currency_code', 'cash_vouchers.doc_num as voucher_number']);

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['production_expense_requests.doc_num', 'maintenance_work_orders.doc_num', 'fixed_assets.asset_name', 'production_expense_requests.reason', 'production_expense_requests.status']))
            ->addColumn('select', fn (ProductionExpenseRequest $row): string => $this->selection($request, $row->doc_num, ! $row->trashed() && $row->status === ProductionExpenseRequest::StatusSubmitted, 'maintenance.expenses.delete'))
            ->editColumn('doc_num', fn (ProductionExpenseRequest $row): string => $row->trashed() ? e($row->doc_num) : '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.maintenance.expenses.show', $row)).'">'.e($row->doc_num).'</a>')
            ->editColumn('request_date', fn (ProductionExpenseRequest $row): string => e($row->request_date?->format('Y-m-d') ?? ''))
            ->editColumn('status', fn (ProductionExpenseRequest $row): string => $this->badge(__('production_execution.statuses.'.$row->status)))
            ->addColumn('actions', fn (ProductionExpenseRequest $row): string => $this->expenseActions($request, $row))
            ->orderColumn('work_order_number', 'maintenance_work_orders.doc_num $1')
            ->orderColumn('asset_name', 'fixed_assets.asset_name $1')
            ->orderColumn('currency_code', 'currencies.code $1')
            ->orderColumn('voucher_number', 'cash_vouchers.doc_num $1')
            ->rawColumns(['select', 'doc_num', 'status', 'actions'])->removeColumn('id')->removeColumn('company_id')->toJson();
    }

    private function actions(Request $request, MaintenanceWorkOrder $row): string
    {
        if ($row->trashed()) {
            return $this->menu([
                $request->user()?->can('maintenance.orders.restore') ? $this->actionButton(route('admin.maintenance.orders.restore', $row->doc_num), __('maintenance.actions.restore'), 'restore') : '',
            ]);
        }

        return $this->menu([
            $request->user()?->can('maintenance.orders.view') ? $this->link(route('admin.maintenance.orders.show', $row), __('maintenance.actions.view')) : '',
            $row->status === MaintenanceWorkOrder::StatusDraft && $request->user()?->can('maintenance.orders.edit') ? $this->link(route('admin.maintenance.orders.edit', $row), __('common.actions.edit')) : '',
            $request->user()?->can('maintenance.orders.print') ? $this->link(route('admin.maintenance.orders.print', $row), __('common.actions.print'), true) : '',
            $row->status === MaintenanceWorkOrder::StatusDraft && $request->user()?->can('maintenance.orders.approve') ? $this->actionButton(route('admin.maintenance.orders.approve', $row), __('maintenance.actions.approve')) : '',
            $row->status === MaintenanceWorkOrder::StatusApproved && $request->user()?->can('maintenance.orders.start') ? $this->actionButton(route('admin.maintenance.orders.start', $row), __('maintenance.actions.start')) : '',
            $row->status === MaintenanceWorkOrder::StatusInProgress && $request->user()?->can('maintenance.orders.complete') ? $this->link(route('admin.maintenance.orders.complete-form', $row), __('maintenance.actions.complete')) : '',
            $row->status === MaintenanceWorkOrder::StatusCompleted && $request->user()?->can('maintenance.orders.close') ? $this->actionButton(route('admin.maintenance.orders.close', $row), __('maintenance.actions.close')) : '',
            $row->status === MaintenanceWorkOrder::StatusDraft && $request->user()?->can('maintenance.orders.delete') ? $this->actionButton(route('admin.maintenance.orders.destroy', $row), __('common.actions.delete'), 'delete', true) : '',
        ]);
    }

    private function requestActions(Request $request, MaintenanceRequest $row): string
    {
        if ($row->trashed()) {
            return $this->menu([
                $request->user()?->can('maintenance.requests.restore') ? $this->actionButton(route('admin.maintenance.requests.restore', $row->doc_num), __('maintenance.actions.restore'), 'restore') : '',
            ]);
        }

        return $this->menu([
            $request->user()?->can('maintenance.requests.view') ? $this->link(route('admin.maintenance.requests.show', $row), __('maintenance.actions.view')) : '',
            $row->status === MaintenanceRequest::StatusOpen && blank($row->work_order_number) && $request->user()?->can('maintenance.requests.edit') ? $this->link(route('admin.maintenance.requests.edit', $row), __('common.actions.edit')) : '',
            $row->status === MaintenanceRequest::StatusOpen && $request->user()?->can('maintenance.orders.create') ? $this->link(route('admin.maintenance.orders.create', ['request' => $row->doc_num]), __('maintenance.actions.create_order')) : '',
            $row->status === MaintenanceRequest::StatusOpen && blank($row->work_order_number) && $request->user()?->can('maintenance.requests.delete') ? $this->actionButton(route('admin.maintenance.requests.destroy', $row), __('maintenance.actions.delete'), 'delete', true) : '',
        ]);
    }

    private function materialActions(Request $request, MaintenanceMaterialRequest $row): string
    {
        if ($row->trashed()) {
            return $this->menu([
                $request->user()?->can('maintenance.material_requests.restore') ? $this->actionButton(route('admin.maintenance.material-requests.restore', $row->doc_num), __('maintenance.actions.restore'), 'restore') : '',
            ]);
        }

        return $this->menu([
            $request->user()?->can('maintenance.material_requests.view') ? $this->link(route('admin.maintenance.material-requests.show', $row), __('maintenance.actions.view')) : '',
            $row->status === MaintenanceMaterialRequest::StatusSubmitted && $request->user()?->can('maintenance.material_requests.edit') ? $this->link(route('admin.maintenance.material-requests.edit', $row), __('common.actions.edit')) : '',
            $row->status === MaintenanceMaterialRequest::StatusSubmitted && $request->user()?->can('maintenance.material_requests.approve') ? $this->actionButton(route('admin.maintenance.material-requests.approve', $row), __('maintenance.actions.approve')) : '',
            $row->status === MaintenanceMaterialRequest::StatusApproved && $request->user()?->can('maintenance.material_requests.issue') ? $this->actionButton(route('admin.maintenance.material-requests.issue', $row), __('maintenance.actions.issue')) : '',
            in_array($row->status, [MaintenanceMaterialRequest::StatusIssued, MaintenanceMaterialRequest::StatusPartiallyReturned], true) && $request->user()?->can('maintenance.material_requests.return') ? $this->actionButton(route('admin.maintenance.material-requests.return', $row), __('maintenance.actions.return_unused')) : '',
            $row->status === MaintenanceMaterialRequest::StatusSubmitted && $request->user()?->can('maintenance.material_requests.delete') ? $this->actionButton(route('admin.maintenance.material-requests.destroy', $row), __('common.actions.delete'), 'delete', true) : '',
        ]);
    }

    private function expenseActions(Request $request, ProductionExpenseRequest $row): string
    {
        if ($row->trashed()) {
            return $this->menu([
                $request->user()?->can('maintenance.expenses.restore') ? $this->actionButton(route('admin.maintenance.expenses.restore', $row->doc_num), __('maintenance.actions.restore'), 'restore') : '',
            ]);
        }

        return $this->menu([
            $request->user()?->can('maintenance.expenses.view') ? $this->link(route('admin.maintenance.expenses.show', $row), __('maintenance.actions.view')) : '',
            $row->status === ProductionExpenseRequest::StatusSubmitted && $request->user()?->can('maintenance.expenses.edit') ? $this->link(route('admin.maintenance.expenses.edit', $row), __('common.actions.edit')) : '',
            $row->status === ProductionExpenseRequest::StatusSubmitted && $request->user()?->can('maintenance.expenses.approve') ? $this->actionButton(route('admin.maintenance.expenses.approve', $row), __('maintenance.actions.approve')) : '',
            $row->status === ProductionExpenseRequest::StatusApproved && $request->user()?->can('maintenance.expenses.pay') ? $this->actionButton(route('admin.maintenance.expenses.pay', $row), __('maintenance.actions.pay')) : '',
            $row->status === ProductionExpenseRequest::StatusPaid && $request->user()?->can('maintenance.expenses.reverse') ? '<button class="dropdown-item text-danger" type="button" data-action="reason" data-reason-key="reason" data-url="'.e(route('admin.maintenance.expenses.reverse', $row)).'">'.e(__('maintenance.actions.reverse')).'</button>' : '',
            $row->status === ProductionExpenseRequest::StatusSubmitted && $request->user()?->can('maintenance.expenses.delete') ? $this->actionButton(route('admin.maintenance.expenses.destroy', $row), __('common.actions.delete'), 'delete', true) : '',
        ]);
    }

    private function requestTrashQuery(Request $request): Builder
    {
        if (! $request->user()?->can('maintenance.requests.view_trashed')) {
            return MaintenanceRequest::query();
        }

        return match ($request->string('trash_filter')->toString()) {
            'trashed' => MaintenanceRequest::onlyTrashed(),
            'all' => MaintenanceRequest::withTrashed(),
            default => MaintenanceRequest::query(),
        };
    }

    /** @param class-string<MaintenanceWorkOrder|MaintenanceMaterialRequest|ProductionExpenseRequest> $model */
    private function trashQuery(Request $request, string $model, string $permission): Builder
    {
        if (! $request->user()?->can($permission)) {
            return $model::query();
        }

        return match ($request->string('trash_filter')->toString()) {
            'trashed' => $model::onlyTrashed(),
            'all' => $model::withTrashed(),
            default => $model::query(),
        };
    }

    private function selection(Request $request, string $docNum, bool $enabled, string $permission): string
    {
        $disabled = ! $enabled || ! $request->user()?->can($permission);

        return '<div class="form-check mb-0 d-flex justify-content-center"><input class="form-check-input js-record-select" type="checkbox" value="'.e($docNum).'" data-doc-num="'.e($docNum).'" aria-label="'.e(__('maintenance.actions.select_record', ['number' => $docNum])).'"'.($disabled ? ' disabled' : '').'></div>';
    }

    /** @param list<string> $items */
    private function menu(array $items): string
    {
        $items = collect($items)->filter()->implode('');
        if ($items === '') {
            return '';
        }

        return '<div class="dropdown font-sans-serif position-static"><button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="'.e(__('common.fields.actions')).'"><span class="fas fa-ellipsis-h"></span></button><div class="dropdown-menu dropdown-menu-end border py-2">'.$items.'</div></div>';
    }

    private function link(string $url, string $label, bool $newTab = false): string
    {
        return '<a class="dropdown-item" href="'.e($url).'"'.($newTab ? ' target="_blank" rel="noopener"' : '').'>'.e($label).'</a>';
    }

    private function actionButton(string $url, string $label, string $action = 'post', bool $danger = false): string
    {
        return '<button class="dropdown-item'.($danger ? ' text-danger' : '').'" type="button" data-action="'.e($action).'" data-url="'.e($url).'"'.($action === 'delete' ? ' data-confirm="'.e(__('maintenance.messages.confirm_delete')).'"' : '').'>'.e($label).'</button>';
    }

    /** @param list<string> $columns */
    private function filter(Builder $query, Request $request, array $columns): void
    {
        $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => $columns]);
        }
    }

    private function hasContext(array $context): bool
    {
        return (bool) ($context['company_id'] && $context['financial_period_id'] && $context['branch_id']);
    }

    private function badge(string $label): string
    {
        return '<span class="badge rounded-pill badge-subtle-secondary">'.e($label).'</span>';
    }
}
