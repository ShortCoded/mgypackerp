<?php

namespace Modules\Production\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\DataTableSearchService;
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
        $query = ProductionOrder::query()
            ->when($this->hasContext($context), fn ($query) => $query
                ->where('production_orders.company_id', $context['company_id'])
                ->where('production_orders.financial_period_id', $context['financial_period_id'])
                ->where('production_orders.branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'production_orders.sales_order_id')
            ->select(['production_orders.*', 'sales_orders.doc_num as sales_order_number'])
            ->withCount(['lines', 'runs']);

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['production_orders.doc_num', 'sales_orders.doc_num', 'production_orders.status', 'production_orders.source_type']))
            ->editColumn('doc_num', fn (ProductionOrder $order): string => '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.production.work-orders.show', $order)).'">'.e($order->doc_num).'</a>')
            ->editColumn('production_order_date', fn (ProductionOrder $order): string => e($order->production_order_date?->format('Y-m-d') ?? ''))
            ->editColumn('expected_delivery_date', fn (ProductionOrder $order): string => e($order->expected_delivery_date?->format('Y-m-d') ?? '—'))
            ->editColumn('status', fn (ProductionOrder $order): string => $this->badge(__('production_execution.statuses.'.$order->status), 'secondary'))
            ->orderColumn('sales_order_number', 'sales_orders.doc_num $1')
            ->orderColumn('lines_count', 'lines_count $1')
            ->orderColumn('runs_count', 'runs_count $1')
            ->rawColumns(['doc_num', 'status'])->removeColumn('id')->removeColumn('customer_id')->removeColumn('company_id')->toJson();
    }

    public function runs(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = ProductionRun::query()
            ->when($this->hasContext($context), fn ($query) => $query
                ->where('production_runs.company_id', $context['company_id'])
                ->where('production_runs.financial_period_id', $context['financial_period_id'])
                ->where('production_runs.branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->leftJoin('production_orders', 'production_orders.id', '=', 'production_runs.production_order_id')
            ->leftJoin('products', 'products.id', '=', 'production_runs.product_id')
            ->leftJoin('fixed_assets', 'fixed_assets.id', '=', 'production_runs.fixed_asset_id')
            ->leftJoin('production_order_stage_snapshots', 'production_order_stage_snapshots.id', '=', 'production_runs.production_order_stage_snapshot_id')
            ->select(['production_runs.*', 'production_orders.doc_num as order_number', 'products.name as product_name', 'fixed_assets.asset_name as asset_name', 'production_order_stage_snapshots.stage_name as stage_name']);

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['production_runs.run_number', 'production_orders.doc_num', 'products.name', 'fixed_assets.asset_name', 'production_order_stage_snapshots.stage_name', 'production_runs.status']))
            ->editColumn('run_number', fn (ProductionRun $run): string => '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.production.runs.show', $run)).'">'.e($run->run_number).'</a>')
            ->editColumn('planned_start_at', fn (ProductionRun $run): string => e($run->planned_start_at?->format('Y-m-d H:i') ?? ''))
            ->editColumn('status', fn (ProductionRun $run): string => $this->badge(__('production_execution.statuses.'.$run->status), 'secondary'))
            ->orderColumn('order_number', 'production_orders.doc_num $1')
            ->orderColumn('product_name', 'products.name $1')
            ->orderColumn('stage_name', 'production_order_stage_snapshots.sequence $1')
            ->orderColumn('asset_name', 'fixed_assets.asset_name $1')
            ->rawColumns(['run_number', 'status'])->removeColumn('id')->removeColumn('company_id')->toJson();
    }

    public function materialRequests(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = ProductionMaterialRequest::query()
            ->when($this->hasContext($context), fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->leftJoin('production_runs', 'production_runs.id', '=', 'production_material_requests.production_run_id')
            ->leftJoin('branch_stores', 'branch_stores.id', '=', 'production_material_requests.branch_store_id')
            ->leftJoin('purchase_requisitions', 'purchase_requisitions.id', '=', 'production_material_requests.purchase_requisition_id')
            ->select(['production_material_requests.*', 'production_runs.run_number', 'branch_stores.name as store_name', 'purchase_requisitions.doc_num as purchase_requisition_number'])
            ->withCount('lines')
            ->withExists(['lines as has_shortage' => fn ($query) => $query->where('shortage_quantity', '>', 0)]);

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['production_material_requests.doc_num', 'production_runs.run_number', 'branch_stores.name', 'purchase_requisitions.doc_num', 'production_material_requests.status']))
            ->editColumn('request_date', fn (ProductionMaterialRequest $row): string => e($row->request_date?->format('Y-m-d') ?? ''))
            ->editColumn('status', fn (ProductionMaterialRequest $row): string => $this->badge(__('production_execution.statuses.'.$row->status), 'secondary'))
            ->addColumn('actions', fn (ProductionMaterialRequest $row): string => $this->materialRequestActions($request, $row))
            ->orderColumn('run_number', 'production_runs.run_number $1')
            ->orderColumn('store_name', 'branch_stores.name $1')
            ->orderColumn('purchase_requisition_number', 'purchase_requisitions.doc_num $1')
            ->rawColumns(['status', 'actions'])->removeColumn('id')->removeColumn('company_id')->toJson();
    }

    public function expenses(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = ProductionExpenseRequest::query()
            ->when($this->hasContext($context), fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->leftJoin('production_runs', 'production_runs.id', '=', 'production_expense_requests.production_run_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'production_expense_requests.currency_id')
            ->leftJoin('cash_vouchers', 'cash_vouchers.id', '=', 'production_expense_requests.cash_voucher_id')
            ->select(['production_expense_requests.*', 'production_runs.run_number', 'currencies.code as currency_code', 'cash_vouchers.doc_num as voucher_number']);

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['production_expense_requests.doc_num', 'production_runs.run_number', 'production_expense_requests.reason', 'production_expense_requests.status']))
            ->editColumn('request_date', fn (ProductionExpenseRequest $row): string => e($row->request_date?->format('Y-m-d') ?? ''))
            ->editColumn('status', fn (ProductionExpenseRequest $row): string => $this->badge(__('production_execution.statuses.'.$row->status), 'secondary'))
            ->addColumn('actions', fn (ProductionExpenseRequest $row): string => $this->expenseActions($request, $row))
            ->orderColumn('run_number', 'production_runs.run_number $1')
            ->orderColumn('currency_code', 'currencies.code $1')
            ->orderColumn('voucher_number', 'cash_vouchers.doc_num $1')
            ->rawColumns(['status', 'actions'])->removeColumn('id')->removeColumn('company_id')->toJson();
    }

    public function quality(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $query = ProductionQualityInspection::query()
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
            ->select(['quality_inspections.*', 'production_runs.run_number', 'products.name as subject_product_name', 'branch_stores.name as subject_store_name', 'production_order_stage_snapshots.stage_name'])
            ->withCount('reports');

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $this->filter($query, $request, ['quality_inspections.doc_num', 'production_runs.run_number', 'products.name', 'branch_stores.name', 'quality_inspections.source_reference', 'quality_inspections.batch_lot', 'production_order_stage_snapshots.stage_name', 'quality_inspections.result', 'quality_inspections.status']))
            ->editColumn('doc_num', fn (ProductionQualityInspection $row): string => '<a class="fw-semibold" data-row-primary-link href="'.e(route('admin.production.quality.show', $row->getKey())).'">'.e($row->doc_num).'</a>')
            ->editColumn('requested_at', fn (ProductionQualityInspection $row): string => e($row->requested_at?->format('Y-m-d H:i') ?? ''))
            ->addColumn('subject', fn (ProductionQualityInspection $row): string => e($this->qualitySubject($row)))
            ->editColumn('result', fn (ProductionQualityInspection $row): string => $this->badge(__('production_execution.quality_results.'.$row->result), $row->result === 'passed' ? 'success' : ($row->result === 'failed' ? 'danger' : 'warning')))
            ->editColumn('disposition', fn (ProductionQualityInspection $row): string => $row->disposition ? e(__('production_execution.quality_dispositions.'.$row->disposition)) : '—')
            ->editColumn('status', fn (ProductionQualityInspection $row): string => $this->badge(__('production_execution.statuses.'.$row->status), 'secondary'))
            ->addColumn('evidence_count', fn (ProductionQualityInspection $row): int => count($row->evidence ?? []))
            ->addColumn('actions', fn (ProductionQualityInspection $row): string => $this->qualityActions($request, $row))
            ->orderColumn('run_number', 'production_runs.run_number $1')
            ->orderColumn('subject', 'quality_inspections.subject_type $1')
            ->orderColumn('stage_name', 'production_order_stage_snapshots.sequence $1')
            ->rawColumns(['doc_num', 'result', 'status', 'actions'])->removeColumn('id')->removeColumn('company_id')->toJson();
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

    /** @param class-string<ProductionStage> $model */
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
        return collect([
            $row->status === ProductionMaterialRequest::StatusSubmitted && $request->user()?->can('production.material_requests.approve') ? '<button class="btn btn-sm btn-falcon-success" data-action="post" data-url="'.e(route('admin.production.material-requests.approve', $row)).'">'.e(__('production_execution.actions.approve')).'</button>' : '',
            in_array($row->status, [ProductionMaterialRequest::StatusShortage, ProductionMaterialRequest::StatusPartiallyIssued], true) && $row->has_shortage && $request->user()?->can('production.material_requests.approve') ? '<button class="btn btn-sm btn-falcon-warning" data-action="post" data-url="'.e(route('admin.production.material-requests.allocate-shortage', $row)).'">'.e(__('production_execution.actions.recheck_stock')).'</button>' : '',
            in_array($row->status, [ProductionMaterialRequest::StatusApproved, ProductionMaterialRequest::StatusShortage, ProductionMaterialRequest::StatusPartiallyIssued], true) && $request->user()?->can('production.material_requests.issue') ? '<button class="btn btn-sm btn-primary" data-action="post" data-url="'.e(route('admin.production.material-requests.issue', $row)).'">'.e(__('production_execution.actions.issue')).'</button>' : '',
        ])->filter()->implode(' ');
    }

    private function expenseActions(Request $request, ProductionExpenseRequest $row): string
    {
        return collect([
            $row->status === ProductionExpenseRequest::StatusSubmitted && $request->user()?->can('production.expenses.approve') ? '<button class="btn btn-sm btn-falcon-success" data-action="post" data-url="'.e(route('admin.production.expenses.approve', $row)).'">'.e(__('production_execution.actions.approve')).'</button>' : '',
            $row->status === ProductionExpenseRequest::StatusApproved && $request->user()?->can('production.expenses.pay') ? '<button class="btn btn-sm btn-primary" data-action="post" data-url="'.e(route('admin.production.expenses.pay', $row)).'">'.e(__('production_execution.actions.pay')).'</button>' : '',
            $row->status === ProductionExpenseRequest::StatusPaid && $request->user()?->can('production.expenses.reverse') ? '<button class="btn btn-sm btn-falcon-danger" data-action="reason" data-reason-key="reason" data-url="'.e(route('admin.production.expenses.reverse', $row)).'">'.e(__('production_execution.actions.reverse')).'</button>' : '',
        ])->filter()->implode(' ');
    }

    private function qualityActions(Request $request, ProductionQualityInspection $row): string
    {
        $actions = ['<a class="btn btn-sm btn-falcon-primary" href="'.e(route('admin.production.quality.show', $row->getKey())).'">'.e(__('production_execution.actions.view')).'</a>'];

        if ($row->status === ProductionQualityInspection::StatusDraft && $request->user()?->can('production.quality.receive')) {
            $actions[] = '<button class="btn btn-sm btn-primary" data-action="post" data-url="'.e(route('admin.production.quality.receive', $row->getKey())).'">'.e(__('production_execution.actions.receive_inspection')).'</button>';
        }
        if ($row->status === ProductionQualityInspection::StatusReceived && $request->user()?->can('production.quality.start')) {
            $actions[] = '<button class="btn btn-sm btn-primary" data-action="post" data-url="'.e(route('admin.production.quality.start', $row->getKey())).'">'.e(__('production_execution.actions.start_inspection')).'</button>';
        }
        if ($row->status === ProductionQualityInspection::StatusSubmitted && $request->user()?->can('production.quality.review')) {
            $actions[] = '<button class="btn btn-sm btn-falcon-success" data-action="post" data-url="'.e(route('admin.production.quality.approve', $row->getKey())).'">'.e(__('production_execution.actions.approve')).'</button>';
            $actions[] = '<button class="btn btn-sm btn-falcon-danger" data-action="reason" data-prompt="'.e(__('production_execution.quality.review_reason_prompt')).'" data-reason-key="reason" data-url="'.e(route('admin.production.quality.reject', $row->getKey())).'">'.e(__('production_execution.actions.reject')).'</button>';
        }
        if (in_array($row->status, [ProductionQualityInspection::StatusApproved, ProductionQualityInspection::StatusRejected], true)
            && $request->user()?->can('production.quality.close')) {
            $actions[] = '<button class="btn btn-sm btn-primary" data-action="post" data-url="'.e(route('admin.production.quality.close', $row->getKey())).'">'.e(__('production_execution.actions.close_inspection')).'</button>';
        }
        if ($row->status === ProductionQualityInspection::StatusClosed
            && $request->user()?->can('production.quality.reinspect')) {
            $actions[] = '<button class="btn btn-sm btn-falcon-default" data-action="post" data-url="'.e(route('admin.production.quality.reinspect', $row->getKey())).'">'.e(__('production_execution.actions.reinspect')).'</button>';
        }

        return implode(' ', $actions);
    }
}
