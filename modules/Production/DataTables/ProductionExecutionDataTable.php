<?php

namespace Modules\Production\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionQualityInspectionReport;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionStage;
use Modules\Production\Models\ProductProductionStage;
use Yajra\DataTables\Facades\DataTables;

class ProductionExecutionDataTable
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly DataTableSearchService $search,
        private readonly NumericFormatService $numbers,
        private readonly DateFormatService $dates,
    ) {}

    public function stages(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = $this->trashQuery($request, ProductionStage::class, 'production.stages.view_trashed')
            ->when($context['company_id'], fn ($query) => $query->where('company_id', $context['company_id']), fn ($query) => $query->whereRaw('1 = 0'));

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['code', 'name', 'description', 'output_type', 'status']))
            ->addColumn('duration', fn (ProductionStage $stage): string => $stage->standard_duration_value ? e($stage->standard_duration_value.' '.__('production_execution.duration_units.'.$stage->standard_duration_unit)) : '—')
            ->editColumn('status', fn (ProductionStage $stage): string => $this->badge(__('production_execution.statuses.'.$stage->status), $stage->status === 'active' ? 'success' : 'secondary'))
            ->addColumn('actions', fn (ProductionStage $stage): string => $this->stageActions($request, $stage))
            ->orderColumn('duration', 'standard_duration_value $1')
            ->rawColumns(['status', 'actions'])
            ->removeColumn('id')->removeColumn('company_id')->toJson();
    }

    public function productStages(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = ProductProductionStage::query()
            ->when($context['company_id'], fn ($query) => $query->where('product_production_stages.company_id', $context['company_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->join('products', 'products.id', '=', 'product_production_stages.product_id')
            ->join('production_stages', 'production_stages.id', '=', 'product_production_stages.production_stage_id')
            ->select(['product_production_stages.*', 'products.doc_num as product_code', 'products.name as product_name', 'production_stages.code as stage_code', 'production_stages.name as stage_name']);

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['products.doc_num', 'products.name', 'production_stages.code', 'production_stages.name']))
            ->addColumn('product', function (ProductProductionStage $row) use ($request): string {
                $label = e($row->product_code.' — '.$row->product_name);

                return $request->user()?->can('production.product_stages.manage')
                    ? '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.production.product-stages.edit', $row->product_id)).'">'.$label.'</a>'
                    : $label;
            })
            ->addColumn('stage', fn (ProductProductionStage $row): string => e($row->stage_code.' — '.$row->stage_name))
            ->editColumn('status', fn (ProductProductionStage $row): string => $this->badge(__('production_execution.statuses.'.$row->status), $row->status === 'active' ? 'success' : 'secondary'))
            ->orderColumn('product', 'products.doc_num $1')
            ->orderColumn('stage', 'product_production_stages.sequence $1')
            ->rawColumns(['product', 'status'])->removeColumn('id')->removeColumn('company_id')->toJson();
    }

    public function orders(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $branchType = Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->value('type');
        $canManage = $branchType === Branch::TypeFactory;
        $query = $this->trashQuery($request, ProductionOrder::class, 'production.orders.view_trashed')
            ->when($this->hasContext($context), fn ($query) => $query
                ->where('production_orders.company_id', $context['company_id'])
                ->where('production_orders.financial_period_id', $context['financial_period_id'])
                ->when(
                    $branchType === Branch::TypeAdministrative,
                    fn ($query) => $query->whereIn('production_orders.branch_id', Branch::query()
                        ->where('company_id', $context['company_id'])
                        ->where('type', Branch::TypeFactory)
                        ->select('id')),
                    fn ($query) => $canManage
                        ? $query->where('production_orders.branch_id', $context['branch_id'])
                        : $query->whereRaw('1 = 0'),
                ), fn ($query) => $query->whereRaw('1 = 0'))
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'production_orders.sales_order_id')
            ->leftJoin('customer_invoices', function ($join): void {
                $join->on('customer_invoices.id', '=', 'production_orders.source_id')
                    ->where('production_orders.source_type', 'customer_invoice');
            })
            ->leftJoin('users as created_users', 'created_users.id', '=', 'production_orders.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'production_orders.updated_by')
            ->select([
                'production_orders.*',
                'sales_orders.doc_num as sales_order_number',
                'customer_invoices.doc_num as invoice_number',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ])
            ->withCount(['lines', 'runs']);

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['production_orders.doc_num', 'sales_orders.doc_num', 'customer_invoices.doc_num', 'production_orders.status', 'production_orders.source_type', 'created_users.name', 'updated_users.name']))
            ->addColumn('checkbox', fn (ProductionOrder $order): string => view('modules.production.work-orders.partials.checkbox', ['record' => $order, 'canManage' => $canManage])->render())
            ->editColumn('doc_num', fn (ProductionOrder $order): string => '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.production.work-orders.show', $order)).'">'.e($order->doc_num).'</a>')
            ->addColumn('source_document_number', function (ProductionOrder $order): string {
                $sourceType = __('production_execution.source_types.'.$order->source_type);
                $sourceNumber = $order->sales_order_number ?: $order->invoice_number;

                return $sourceNumber ? $sourceType.' — '.$sourceNumber : $sourceType;
            })
            ->editColumn('production_order_date', fn (ProductionOrder $order): string => $this->dates->formatDate($order->production_order_date, ''))
            ->editColumn('expected_delivery_date', fn (ProductionOrder $order): string => $this->dates->formatDate($order->expected_delivery_date, '—'))
            ->editColumn('status', fn (ProductionOrder $order): string => $this->badge(__('production_execution.statuses.'.$order->status), 'secondary'))
            ->addColumn('created_by', fn (ProductionOrder $order): string => $order->created_by_name ?: __('common.empty_value'))
            ->editColumn('created_at', fn (ProductionOrder $order): string => $this->dates->formatDateTime($order->created_at, ''))
            ->addColumn('updated_by', fn (ProductionOrder $order): string => $order->updated_by_name ?: __('common.empty_value'))
            ->editColumn('updated_at', fn (ProductionOrder $order): string => $this->dates->formatDateTime($order->updated_at, ''))
            ->addColumn('actions', fn (ProductionOrder $order): string => view('modules.production.work-orders.partials.actions', ['record' => $order, 'canManage' => $canManage])->render())
            ->orderColumn('doc_num', 'production_orders.doc_number $1')
            ->orderColumn('source_document_number', 'production_orders.source_type $1')
            ->orderColumn('lines_count', 'lines_count $1')
            ->orderColumn('runs_count', 'runs_count $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->rawColumns(['checkbox', 'doc_num', 'status', 'actions'])
            ->removeColumn('id')
            ->removeColumn('customer_id')
            ->removeColumn('company_id')
            ->removeColumn('source_id')
            ->removeColumn('created_by_name')
            ->removeColumn('updated_by_name')
            ->toJson();
    }

    public function runs(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = $this->trashQuery($request, ProductionRun::class, 'production.runs.view_trashed')
            ->when($this->hasContext($context), fn ($query) => $query
                ->where('production_runs.company_id', $context['company_id'])
                ->where('production_runs.financial_period_id', $context['financial_period_id'])
                ->where('production_runs.branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->leftJoin('production_orders', 'production_orders.id', '=', 'production_runs.production_order_id')
            ->leftJoin('products', 'products.id', '=', 'production_runs.product_id')
            ->leftJoin('fixed_assets', 'fixed_assets.id', '=', 'production_runs.fixed_asset_id')
            ->leftJoin('production_order_stage_snapshots', 'production_order_stage_snapshots.id', '=', 'production_runs.production_order_stage_snapshot_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'production_runs.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'production_runs.updated_by')
            ->select([
                'production_runs.*', 'production_orders.doc_num as order_number', 'products.name as product_name',
                'fixed_assets.asset_name as asset_name', 'production_order_stage_snapshots.stage_name as stage_name',
                'created_users.name as created_by_name', 'updated_users.name as updated_by_name',
            ])
            ->withExists([
                'reservations as has_reservations',
                'progressEntries as has_progress_entries',
                'inspections as has_inspections',
                'inventoryDocuments as has_inventory_documents',
                'materialRequests as has_material_requests',
                'expenseRequests as has_expense_requests',
                'requirements as has_requirement_activity' => fn ($requirements) => $requirements->where(function ($nested): void {
                    $nested->where('reserved_quantity', '>', 0)
                        ->orWhere('issued_quantity', '>', 0)
                        ->orWhere('additional_issued_quantity', '>', 0)
                        ->orWhere('returned_quantity', '>', 0)
                        ->orWhere('consumed_quantity', '>', 0)
                        ->orWhere('waste_quantity', '>', 0);
                }),
            ]);

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['production_runs.run_number', 'production_orders.doc_num', 'products.name', 'fixed_assets.asset_name', 'production_order_stage_snapshots.stage_name', 'production_runs.status', 'created_users.name', 'updated_users.name']))
            ->addColumn('checkbox', fn (ProductionRun $run): string => view('modules.production.runs.partials.checkbox', ['record' => $run, 'canChange' => $this->runCanBeChanged($run)])->render())
            ->editColumn('run_number', fn (ProductionRun $run): string => $run->trashed()
                ? e($run->run_number)
                : '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.production.runs.show', $run)).'">'.e($run->run_number).'</a>')
            ->editColumn('planned_start_at', fn (ProductionRun $run): string => $this->dates->formatDateTime($run->planned_start_at, ''))
            ->editColumn('planned_base_quantity', fn (ProductionRun $run): string => e($this->numbers->format($run->planned_base_quantity)))
            ->editColumn('good_base_quantity', fn (ProductionRun $run): string => e($this->numbers->format($run->good_base_quantity)))
            ->editColumn('status', fn (ProductionRun $run): string => $this->badge(__('production_execution.statuses.'.$run->status), 'secondary'))
            ->addColumn('created_by', fn (ProductionRun $run): string => $run->created_by_name ?: __('common.empty_value'))
            ->editColumn('created_at', fn (ProductionRun $run): string => $this->dates->formatDateTime($run->created_at, ''))
            ->addColumn('updated_by', fn (ProductionRun $run): string => $run->updated_by_name ?: __('common.empty_value'))
            ->editColumn('updated_at', fn (ProductionRun $run): string => $this->dates->formatDateTime($run->updated_at, ''))
            ->addColumn('actions', fn (ProductionRun $run): string => view('modules.production.runs.partials.actions', ['record' => $run, 'canChange' => $this->runCanBeChanged($run)])->render())
            ->orderColumn('order_number', 'production_orders.doc_num $1')
            ->orderColumn('product_name', 'products.name $1')
            ->orderColumn('stage_name', 'production_order_stage_snapshots.sequence $1')
            ->orderColumn('asset_name', 'fixed_assets.asset_name $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->rawColumns(['checkbox', 'run_number', 'status', 'actions'])
            ->removeColumn('id')->removeColumn('company_id')->removeColumn('created_by_name')->removeColumn('updated_by_name')->toJson();
    }

    public function materialRequests(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = $this->trashQuery($request, ProductionMaterialRequest::class, 'production.material_requests.view_trashed')
            ->when($this->hasContext($context), fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->leftJoin('production_runs', 'production_runs.id', '=', 'production_material_requests.production_run_id')
            ->leftJoin('branch_stores', 'branch_stores.id', '=', 'production_material_requests.branch_store_id')
            ->leftJoin('purchase_requisitions', 'purchase_requisitions.id', '=', 'production_material_requests.purchase_requisition_id')
            ->leftJoin('users as material_created_users', 'material_created_users.id', '=', 'production_material_requests.created_by')
            ->leftJoin('users as material_updated_users', 'material_updated_users.id', '=', 'production_material_requests.updated_by')
            ->select([
                'production_material_requests.*', 'production_runs.run_number', 'branch_stores.name as store_name',
                'purchase_requisitions.doc_num as purchase_requisition_number', 'material_created_users.name as created_by_name',
                'material_updated_users.name as updated_by_name',
            ])
            ->withCount('lines')
            ->withExists([
                'lines as has_shortage' => fn ($query) => $query->where('shortage_quantity', '>', 0),
                'inventoryDocuments as has_inventory_documents',
            ]);

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['production_material_requests.doc_num', 'production_runs.run_number', 'branch_stores.name', 'purchase_requisitions.doc_num', 'production_material_requests.status', 'material_created_users.name', 'material_updated_users.name']))
            ->addColumn('checkbox', fn (ProductionMaterialRequest $row): string => view('modules.production.material-requests.partials.checkbox', ['record' => $row, 'canChange' => $row->status === ProductionMaterialRequest::StatusSubmitted && ! $row->has_inventory_documents])->render())
            ->editColumn('doc_num', fn (ProductionMaterialRequest $row): string => $row->trashed()
                ? e($row->doc_num)
                : '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.production.material-requests.show', $row)).'">'.e($row->doc_num).'</a>')
            ->editColumn('request_date', fn (ProductionMaterialRequest $row): string => e($this->dates->formatDate($row->request_date, '')))
            ->editColumn('request_type', fn (ProductionMaterialRequest $row): string => e(__('production_execution.material_request_types.'.$row->request_type)))
            ->editColumn('status', fn (ProductionMaterialRequest $row): string => $this->badge(__('production_execution.statuses.'.$row->status), 'secondary'))
            ->addColumn('created_by', fn (ProductionMaterialRequest $row): string => $row->created_by_name ?: __('common.empty_value'))
            ->editColumn('created_at', fn (ProductionMaterialRequest $row): string => $this->dates->formatDateTime($row->created_at, ''))
            ->addColumn('updated_by', fn (ProductionMaterialRequest $row): string => $row->updated_by_name ?: __('common.empty_value'))
            ->editColumn('updated_at', fn (ProductionMaterialRequest $row): string => $this->dates->formatDateTime($row->updated_at, ''))
            ->addColumn('actions', fn (ProductionMaterialRequest $row): string => $this->materialRequestActions($request, $row))
            ->orderColumn('run_number', 'production_runs.run_number $1')
            ->orderColumn('store_name', 'branch_stores.name $1')
            ->orderColumn('purchase_requisition_number', 'purchase_requisitions.doc_num $1')
            ->orderColumn('created_by', 'material_created_users.name $1')
            ->orderColumn('updated_by', 'material_updated_users.name $1')
            ->rawColumns(['checkbox', 'doc_num', 'status', 'actions'])
            ->removeColumn('id')->removeColumn('company_id')->removeColumn('created_by_name')->removeColumn('updated_by_name')->toJson();
    }

    public function expenses(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = $this->trashQuery($request, ProductionExpenseRequest::class, 'production.expenses.view_trashed')
            ->when($this->hasContext($context), fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereNotNull('production_expense_requests.production_run_id')
            ->leftJoin('production_runs', 'production_runs.id', '=', 'production_expense_requests.production_run_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'production_expense_requests.currency_id')
            ->leftJoin('cash_vouchers', 'cash_vouchers.id', '=', 'production_expense_requests.cash_voucher_id')
            ->leftJoin('users as expense_created_users', 'expense_created_users.id', '=', 'production_expense_requests.created_by')
            ->leftJoin('users as expense_updated_users', 'expense_updated_users.id', '=', 'production_expense_requests.updated_by')
            ->select([
                'production_expense_requests.*', 'production_runs.run_number', 'currencies.code as currency_code',
                'cash_vouchers.doc_num as voucher_number', 'expense_created_users.name as created_by_name',
                'expense_updated_users.name as updated_by_name',
            ]);

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['production_expense_requests.doc_num', 'production_runs.run_number', 'production_expense_requests.reason', 'production_expense_requests.status', 'expense_created_users.name', 'expense_updated_users.name']))
            ->addColumn('checkbox', fn (ProductionExpenseRequest $row): string => view('modules.production.expenses.partials.checkbox', ['record' => $row, 'canChange' => $row->status === ProductionExpenseRequest::StatusSubmitted])->render())
            ->editColumn('doc_num', fn (ProductionExpenseRequest $row): string => $row->trashed()
                ? e($row->doc_num)
                : '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.production.expenses.show', $row)).'">'.e($row->doc_num).'</a>')
            ->editColumn('request_date', fn (ProductionExpenseRequest $row): string => e($this->dates->formatDate($row->request_date, '')))
            ->editColumn('amount', fn (ProductionExpenseRequest $row): string => e($this->numbers->format($row->amount)))
            ->editColumn('status', fn (ProductionExpenseRequest $row): string => $this->badge(__('production_execution.statuses.'.$row->status), 'secondary'))
            ->addColumn('created_by', fn (ProductionExpenseRequest $row): string => $row->created_by_name ?: __('common.empty_value'))
            ->editColumn('created_at', fn (ProductionExpenseRequest $row): string => $this->dates->formatDateTime($row->created_at, ''))
            ->addColumn('updated_by', fn (ProductionExpenseRequest $row): string => $row->updated_by_name ?: __('common.empty_value'))
            ->editColumn('updated_at', fn (ProductionExpenseRequest $row): string => $this->dates->formatDateTime($row->updated_at, ''))
            ->addColumn('actions', fn (ProductionExpenseRequest $row): string => $this->expenseActions($request, $row))
            ->orderColumn('run_number', 'production_runs.run_number $1')
            ->orderColumn('currency_code', 'currencies.code $1')
            ->orderColumn('voucher_number', 'cash_vouchers.doc_num $1')
            ->orderColumn('created_by', 'expense_created_users.name $1')
            ->orderColumn('updated_by', 'expense_updated_users.name $1')
            ->rawColumns(['checkbox', 'doc_num', 'status', 'actions'])
            ->removeColumn('id')->removeColumn('company_id')->removeColumn('created_by_name')->removeColumn('updated_by_name')->toJson();
    }

    public function quality(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = $this->trashQuery($request, ProductionQualityInspection::class, 'production.quality.view_trashed')
            ->when($this->hasContext($context), fn ($query) => $query
                ->where('quality_inspections.company_id', $context['company_id'])
                ->where('quality_inspections.financial_period_id', $context['financial_period_id'])
                ->where('quality_inspections.branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->leftJoin('production_runs', 'production_runs.id', '=', 'quality_inspections.production_run_id')
            ->leftJoin('products', 'products.id', '=', 'quality_inspections.product_id')
            ->leftJoin('branch_stores', 'branch_stores.id', '=', 'quality_inspections.branch_store_id')
            ->leftJoin('production_order_stage_snapshots', 'production_order_stage_snapshots.id', '=', 'quality_inspections.production_order_stage_id')
            ->when($request->string('scope')->toString() === 'active', fn ($query) => $query->whereIn('quality_inspections.status', [
                ProductionQualityInspection::StatusDraft,
                ProductionQualityInspection::StatusReceived,
                ProductionQualityInspection::StatusInProgress,
                ProductionQualityInspection::StatusSubmitted,
            ]))
            ->when($request->filled('subject_type'), fn ($query) => $query->where('quality_inspections.subject_type', $request->string('subject_type')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('quality_inspections.status', $request->string('status')->toString()))
            ->when($request->filled('result'), fn ($query) => $query->where('quality_inspections.result', $request->string('result')->toString()))
            ->when($request->filled('disposition'), fn ($query) => $query->where('quality_inspections.disposition', $request->string('disposition')->toString()))
            ->when($request->integer('product_id'), fn ($query, $productId) => $query->where('quality_inspections.product_id', $productId))
            ->when($request->integer('branch_store_id'), fn ($query, $storeId) => $query->where('quality_inspections.branch_store_id', $storeId))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('quality_inspections.requested_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('quality_inspections.requested_at', '<=', $request->date('to')))
            ->select(['quality_inspections.*', 'production_runs.run_number', 'products.name as subject_product_name', 'branch_stores.name as subject_store_name', 'production_order_stage_snapshots.stage_name'])
            ->withCount('reports');

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['quality_inspections.doc_num', 'production_runs.run_number', 'products.name', 'branch_stores.name', 'quality_inspections.source_reference', 'quality_inspections.batch_lot', 'production_order_stage_snapshots.stage_name', 'quality_inspections.result', 'quality_inspections.status']))
            ->addColumn('select', fn (ProductionQualityInspection $row): string => $this->qualitySelection($request, $row))
            ->editColumn('doc_num', fn (ProductionQualityInspection $row): string => $row->trashed() ? e($row->doc_num) : '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.production.quality.show', $row->getKey())).'">'.e($row->doc_num).'</a>')
            ->editColumn('requested_at', fn (ProductionQualityInspection $row): string => e($row->requested_at?->format('Y-m-d H:i') ?? ''))
            ->addColumn('subject', fn (ProductionQualityInspection $row): string => e($this->qualitySubject($row)))
            ->editColumn('result', fn (ProductionQualityInspection $row): string => $this->badge(__('production_execution.quality_results.'.$row->result), $row->result === 'passed' ? 'success' : ($row->result === 'failed' ? 'danger' : 'warning')))
            ->editColumn('disposition', fn (ProductionQualityInspection $row): string => $row->disposition ? e(__('production_execution.quality_dispositions.'.$row->disposition)) : '—')
            ->editColumn('affected_base_quantity', fn (ProductionQualityInspection $row): string => e($this->numbers->format($row->affected_base_quantity)) ?: '—')
            ->editColumn('status', fn (ProductionQualityInspection $row): string => $this->badge(__('production_execution.statuses.'.$row->status), 'secondary'))
            ->addColumn('evidence_count', fn (ProductionQualityInspection $row): int => count($row->evidence ?? []))
            ->addColumn('actions', fn (ProductionQualityInspection $row): string => $this->qualityActions($request, $row))
            ->orderColumn('run_number', 'production_runs.run_number $1')
            ->orderColumn('subject', 'quality_inspections.subject_type $1')
            ->orderColumn('stage_name', 'production_order_stage_snapshots.sequence $1')
            ->rawColumns(['select', 'doc_num', 'result', 'status', 'actions'])->removeColumn('id')->removeColumn('company_id')->toJson();
    }

    public function qualityReports(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = ProductionQualityInspectionReport::query()
            ->join('quality_inspections', 'quality_inspections.id', '=', 'quality_inspection_reports.quality_inspection_id')
            ->when($this->hasContext($context), fn ($query) => $query
                ->where('quality_inspections.company_id', $context['company_id'])
                ->where('quality_inspections.financial_period_id', $context['financial_period_id'])
                ->where('quality_inspections.branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->leftJoin('production_runs', 'production_runs.id', '=', 'quality_inspections.production_run_id')
            ->leftJoin('products', 'products.id', '=', 'quality_inspections.product_id')
            ->leftJoin('branch_stores', 'branch_stores.id', '=', 'quality_inspections.branch_store_id')
            ->leftJoin('users', 'users.id', '=', 'quality_inspection_reports.submitted_by')
            ->when($request->filled('subject_type'), fn ($query) => $query->where('quality_inspections.subject_type', $request->string('subject_type')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('quality_inspections.status', $request->string('status')->toString()))
            ->when($request->filled('result'), fn ($query) => $query->where('quality_inspections.result', $request->string('result')->toString()))
            ->when($request->filled('disposition'), fn ($query) => $query->where('quality_inspections.disposition', $request->string('disposition')->toString()))
            ->when($request->integer('product_id'), fn ($query, $productId) => $query->where('quality_inspections.product_id', $productId))
            ->when($request->integer('branch_store_id'), fn ($query, $storeId) => $query->where('quality_inspections.branch_store_id', $storeId))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('quality_inspection_reports.reported_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('quality_inspection_reports.reported_at', '<=', $request->date('to')))
            ->select([
                'quality_inspection_reports.*',
                'quality_inspections.doc_num as inspection_number',
                'quality_inspections.subject_type',
                'quality_inspections.source_reference',
                'production_runs.run_number',
                'products.name as subject_product_name',
                'branch_stores.name as subject_store_name',
                'users.name as submitted_by_name',
            ]);

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['quality_inspections.doc_num', 'production_runs.run_number', 'products.name', 'branch_stores.name', 'quality_inspection_reports.observations', 'quality_inspection_reports.result', 'users.name']))
            ->editColumn('inspection_number', fn (ProductionQualityInspectionReport $row): string => '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.production.quality.show', $row->quality_inspection_id)).'">'.e($row->inspection_number).'</a>')
            ->editColumn('reported_at', fn (ProductionQualityInspectionReport $row): string => e($row->reported_at?->format('Y-m-d H:i') ?? ''))
            ->editColumn('result', fn (ProductionQualityInspectionReport $row): string => $this->badge(__('production_execution.quality_results.'.$row->result), $row->result === 'passed' ? 'success' : ($row->result === 'failed' ? 'danger' : 'warning')))
            ->addColumn('subject', fn (ProductionQualityInspectionReport $row): string => e($this->qualitySubject($row)))
            ->addColumn('evidence_count', fn (ProductionQualityInspectionReport $row): int => count($row->evidence ?? []))
            ->orderColumn('inspection_number', 'quality_inspections.doc_num $1')
            ->orderColumn('subject', 'quality_inspections.subject_type $1')
            ->orderColumn('submitted_by_name', 'users.name $1')
            ->rawColumns(['inspection_number', 'result'])
            ->removeColumn('id')->toJson();
    }

    /** @param class-string<Model> $model */
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

    private function runCanBeChanged(ProductionRun $run): bool
    {
        return ! $run->trashed()
            && $run->status === ProductionRun::StatusPlanned
            && ! $run->has_reservations
            && ! $run->has_progress_entries
            && ! $run->has_inspections
            && ! $run->has_inventory_documents
            && ! $run->has_material_requests
            && ! $run->has_expense_requests
            && ! $run->has_requirement_activity;
    }

    private function badge(string $label, string $color): string
    {
        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e($label).'</span>';
    }

    private function qualitySubject(object $row): string
    {
        $type = __('production_execution.quality_subjects.'.($row->subject_type ?: ProductionQualityInspection::SubjectProductionRun));
        $reference = $row->run_number ?: $row->subject_product_name ?: $row->subject_store_name ?: ($row->source_reference ?? null);

        return $reference ? $type.' — '.$reference : $type;
    }

    private function stageActions(Request $request, ProductionStage $stage): string
    {
        if ($stage->trashed()) {
            return $request->user()?->can('production.stages.restore') ? '<button class="btn btn-sm btn-falcon-success" data-action="restore" data-url="'.e(route('admin.production.stages.restore', $stage->public_id)).'">'.e(__('common.actions.restore')).'</button>' : '';
        }

        return collect([
            $request->user()?->can('production.stages.edit') ? '<a class="btn btn-sm btn-falcon-primary" data-row-primary-link href="'.e(route('admin.production.stages.edit', $stage)).'">'.e(__('common.actions.edit')).'</a>' : '',
            $request->user()?->can('production.stages.delete') ? '<button class="btn btn-sm btn-falcon-danger" data-action="delete" data-confirm="'.e(__('production_execution.messages.confirm_delete')).'" data-url="'.e(route('admin.production.stages.destroy', $stage)).'">'.e(__('common.actions.delete')).'</button>' : '',
        ])->filter()->implode(' ');
    }

    private function materialRequestActions(Request $request, ProductionMaterialRequest $row): string
    {
        return view('modules.production.material-requests.partials.actions', [
            'record' => $row,
            'canChange' => $row->status === ProductionMaterialRequest::StatusSubmitted && ! $row->has_inventory_documents,
            'hasShortage' => (bool) $row->has_shortage,
        ])->render();
    }

    private function expenseActions(Request $request, ProductionExpenseRequest $row): string
    {
        return view('modules.production.expenses.partials.actions', [
            'record' => $row,
            'canChange' => $row->status === ProductionExpenseRequest::StatusSubmitted,
        ])->render();
    }

    private function qualityActions(Request $request, ProductionQualityInspection $row): string
    {
        if ($row->trashed()) {
            $restore = $request->user()?->can('production.quality.restore')
                ? '<button class="dropdown-item text-success" type="button" data-action="restore" data-url="'.e(route('admin.production.quality.restore', $row->getKey())).'">'.e(__('common.actions.restore')).'</button>'
                : '';

            return $this->qualityMenu([$restore]);
        }

        $actions = ['<a class="dropdown-item" href="'.e(route('admin.production.quality.show', $row->getKey())).'">'.e(__('production_execution.actions.view')).'</a>'];

        if ($row->status === ProductionQualityInspection::StatusDraft && $request->user()?->can('production.quality.edit')) {
            $actions[] = '<a class="dropdown-item" href="'.e(route('admin.production.quality.edit', $row->getKey())).'">'.e(__('common.actions.edit')).'</a>';
        }

        if ($request->user()?->can('production.quality.print')) {
            $actions[] = '<a class="dropdown-item" target="_blank" rel="noopener" href="'.e(route('admin.production.quality.inspection.print', $row->getKey())).'">'.e(__('common.actions.print')).'</a>';
        }

        if ($row->status === ProductionQualityInspection::StatusDraft && $request->user()?->can('production.quality.receive')) {
            $actions[] = '<button class="dropdown-item" type="button" data-action="post" data-url="'.e(route('admin.production.quality.receive', $row->getKey())).'">'.e(__('production_execution.actions.receive_inspection')).'</button>';
        }
        if ($row->status === ProductionQualityInspection::StatusReceived && $request->user()?->can('production.quality.start')) {
            $actions[] = '<button class="dropdown-item" type="button" data-action="post" data-url="'.e(route('admin.production.quality.start', $row->getKey())).'">'.e(__('production_execution.actions.start_inspection')).'</button>';
        }
        if ($row->status === ProductionQualityInspection::StatusSubmitted && $request->user()?->can('production.quality.review')) {
            $actions[] = '<button class="dropdown-item text-success" type="button" data-action="post" data-url="'.e(route('admin.production.quality.approve', $row->getKey())).'">'.e(__('production_execution.actions.approve')).'</button>';
            $actions[] = '<button class="dropdown-item text-danger" type="button" data-action="reason" data-prompt="'.e(__('production_execution.quality.review_reason_prompt')).'" data-reason-key="reason" data-url="'.e(route('admin.production.quality.reject', $row->getKey())).'">'.e(__('production_execution.actions.reject')).'</button>';
        }
        if (in_array($row->status, [ProductionQualityInspection::StatusApproved, ProductionQualityInspection::StatusRejected], true)
            && $request->user()?->can('production.quality.close')) {
            $actions[] = '<button class="dropdown-item" type="button" data-action="post" data-url="'.e(route('admin.production.quality.close', $row->getKey())).'">'.e(__('production_execution.actions.close_inspection')).'</button>';
        }
        if ($row->status === ProductionQualityInspection::StatusClosed
            && $request->user()?->can('production.quality.reinspect')) {
            $actions[] = '<button class="dropdown-item" type="button" data-action="post" data-url="'.e(route('admin.production.quality.reinspect', $row->getKey())).'">'.e(__('production_execution.actions.reinspect')).'</button>';
        }

        if ($row->status === ProductionQualityInspection::StatusDraft && $request->user()?->can('production.quality.delete')) {
            $actions[] = '<button class="dropdown-item text-danger" type="button" data-action="delete" data-confirm="'.e(__('production_execution.messages.confirm_delete')).'" data-url="'.e(route('admin.production.quality.destroy', $row->getKey())).'">'.e(__('common.actions.delete')).'</button>';
        }

        return $this->qualityMenu($actions);
    }

    private function qualitySelection(Request $request, ProductionQualityInspection $row): string
    {
        $disabled = $row->trashed() || $row->status !== ProductionQualityInspection::StatusDraft || ! $request->user()?->can('production.quality.delete');

        return '<div class="form-check mb-0 d-flex justify-content-center"><input class="form-check-input js-record-select" type="checkbox" value="'.e((string) $row->getKey()).'" data-doc-num="'.e((string) $row->getKey()).'"'.($disabled ? ' disabled' : '').'></div>';
    }

    /** @param list<string> $actions */
    private function qualityMenu(array $actions): string
    {
        $items = collect($actions)->filter()->implode('');

        return $items === '' ? '' : '<div class="dropdown font-sans-serif position-static"><button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal" type="button" data-bs-toggle="dropdown" aria-expanded="false"><span class="fas fa-ellipsis-h"></span></button><div class="dropdown-menu dropdown-menu-end border py-2">'.$items.'</div></div>';
    }
}
