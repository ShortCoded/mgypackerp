<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Maintenance\Services\MaintenanceWorkflowService;
use Modules\Production\DataTables\ProductionExecutionDataTable;
use Modules\Production\Exports\ProductionQualityReportExport;
use Modules\Production\Http\Requests\CloseProductionQualityInspectionRequest;
use Modules\Production\Http\Requests\StoreProductionQualityInspectionReportRequest;
use Modules\Production\Http\Requests\StoreProductionQualityInspectionRequest;
use Modules\Production\Http\Requests\SubmitProductionQualityInspectionRequest;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionQualityInspectionReport;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\QualityInspectionType;
use Modules\Production\Services\ProductionQualityWorkflowService;
use Modules\Production\Services\ProductionReportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProductionQualityController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly ProductionReportService $reports,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
        private readonly ProductionQualityWorkflowService $workflow,
        private readonly NumericFormatService $numbers,
    ) {}

    public function index(): View
    {
        return view('modules.production.quality.index', ['scope' => 'all']);
    }

    public function active(): View
    {
        return view('modules.production.quality.index', ['scope' => 'active']);
    }

    public function reportsIndex(Request $request): View
    {
        [$context, $inspections] = $this->qualityReport($request);
        $selectedProductId = $request->integer('product_id');
        $selectedStoreId = $request->integer('branch_store_id');

        return view('modules.production.quality.reports', [
            'summary' => [
                'total' => $inspections->count(),
                'passed' => $inspections->where('result', 'passed')->count(),
                'failed' => $inspections->where('result', 'failed')->count(),
                'open' => $inspections->whereIn('status', [ProductionQualityInspection::StatusDraft, ProductionQualityInspection::StatusReceived, ProductionQualityInspection::StatusInProgress, ProductionQualityInspection::StatusSubmitted])->count(),
                'affected_quantity' => $inspections->sum(fn (ProductionQualityInspection $inspection): float => (float) $inspection->affected_base_quantity),
            ],
            'products' => Product::query()
                ->where('company_id', $context['company_id'])
                ->whereKey($selectedProductId ?: -1)
                ->get(),
            'stores' => BranchStore::query()
                ->where('branch_id', $context['branch_id'])
                ->whereKey($selectedStoreId ?: -1)
                ->get(),
            'numbers' => $this->numbers,
        ]);
    }

    public function data(Request $request, ProductionExecutionDataTable $dataTable): JsonResponse
    {
        return $dataTable->quality($request);
    }

    public function reportsData(Request $request, ProductionExecutionDataTable $dataTable): JsonResponse
    {
        return $dataTable->qualityReports($request);
    }

    public function create(Request $request): View
    {
        return $this->form($request);
    }

    public function edit(Request $request, int $inspection): View
    {
        $record = $this->scopedInspection($request, $inspection)->loadCount(['reports', 'results']);
        abort_unless($record->status === ProductionQualityInspection::StatusDraft && $record->reports_count === 0 && $record->results_count === 0, 422, __('production_execution.messages.quality_not_editable'));

        return $this->form($request, $record, 'edit');
    }

    public function update(StoreProductionQualityInspectionRequest $request, int $inspection): RedirectResponse
    {
        try {
            $record = $this->scopedInspection($request, $inspection);
            $run = $request->input('subject_type') === ProductionQualityInspection::SubjectProductionRun
                ? $this->scopedRun($request, $request->integer('production_run_id'))
                : null;
            $record = $this->workflow->updateDraft($record, $run, $request->validated());

            return $this->redirectAfterSave($request, $record, __('production_execution.messages.quality_updated'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['quality' => $exception->getMessage()]);
        }
    }

    public function destroy(Request $request, int $inspection): JsonResponse
    {
        try {
            $this->workflow->deleteDraft($this->scopedInspection($request, $inspection));

            return response()->json(['success' => true, 'message' => __('production_execution.messages.quality_deleted')]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function restore(Request $request, int $inspection): JsonResponse
    {
        try {
            $record = $this->scopedTrashedInspection($request, $inspection);
            $this->workflow->restoreDraft($record);

            return response()->json(['success' => true, 'message' => __('production_execution.messages.quality_restored')]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['required', 'integer', 'distinct']])['ids'];
        $context = $this->requiredContext($request);
        $records = ProductionQualityInspection::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->whereIn('id', $ids)
            ->withCount(['reports', 'results'])
            ->get();
        $deleted = 0;
        foreach ($records as $record) {
            if ($record->status !== ProductionQualityInspection::StatusDraft || $record->reports_count > 0 || $record->results_count > 0) {
                continue;
            }
            $this->workflow->deleteDraft($record);
            $deleted++;
        }

        return response()->json(['success' => true, 'message' => __('production_execution.messages.quality_bulk_deleted', ['count' => $deleted])]);
    }

    public function stockBalance(Request $request, InventoryAvailabilityService $availability): JsonResponse
    {
        $context = $this->requiredContext($request);
        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $context['company_id'])->whereNull('deleted_at'))],
            'branch_store_id' => ['required', 'integer', Rule::exists('branch_stores', 'id')->where(fn ($query) => $query->where('branch_id', $context['branch_id'])->whereNull('deleted_at'))],
            'stock_status' => ['required', Rule::in([InventoryTransaction::StatusAvailable, InventoryTransaction::StatusQuarantine, InventoryTransaction::StatusRework, InventoryTransaction::StatusDamaged, InventoryTransaction::StatusScrap])],
            'batch_lot' => ['nullable', 'string', 'max:120'],
        ]);
        $batchLot = filled($data['batch_lot'] ?? null) ? (string) $data['batch_lot'] : null;
        $balance = $availability->forProduct($context['company_id'], (int) $data['branch_store_id'], (int) $data['product_id'], null, null, $data['stock_status'], $batchLot);
        $maximumPosition = '0.00000000';
        $positions = InventoryTransaction::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_store_id', $data['branch_store_id'])
            ->where('product_id', $data['product_id'])
            ->where('stock_status', $data['stock_status'])
            ->when($batchLot !== null, fn ($query) => $query->where('batch_lot', $batchLot))
            ->groupBy(['warehouse_location_id', 'batch_lot'])
            ->get(['warehouse_location_id', 'batch_lot']);
        foreach ($positions as $position) {
            $positionBalance = $availability->forProduct($context['company_id'], (int) $data['branch_store_id'], (int) $data['product_id'], null, $position->warehouse_location_id ? (int) $position->warehouse_location_id : null, $data['stock_status'], $position->batch_lot, true);
            if (bccomp($positionBalance['available'], $maximumPosition, 8) > 0) {
                $maximumPosition = $positionBalance['available'];
            }
        }

        return response()->json(['success' => true, ...$balance, 'inspectable_available' => $maximumPosition]);
    }

    private function form(Request $request, ?ProductionQualityInspection $record = null, string $mode = 'create'): View
    {
        $context = $this->requiredContext($request);
        $record?->load(['run.product', 'run.stageSnapshot', 'product', 'branchStore', 'qualityType', 'stockHold']);
        $selectedRunId = (int) old('production_run_id', $record?->production_run_id ?? $request->integer('run'));
        $selectedProductId = (int) old('product_id', $record?->product_id);
        $selectedStoreId = (int) old('branch_store_id', $record?->branch_store_id);
        $selectedInspectionTypeId = (int) old('quality_inspection_type_id', $record?->quality_inspection_type_id);
        $runs = ProductionRun::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->where(fn ($query) => $query->whereIn('status', [ProductionRun::StatusRunning, ProductionRun::StatusHeld])->orWhere('id', $selectedRunId))
            ->whereKey($selectedRunId ?: -1)
            ->with(['product', 'stageSnapshot'])
            ->get();
        $inspectionTypes = QualityInspectionType::query()
            ->where('company_id', $context['company_id'])
            ->where('is_active', true)
            ->whereKey($selectedInspectionTypeId ?: -1)
            ->get();

        $products = Product::query()
            ->where('company_id', $context['company_id'])
            ->where('status', 'active')
            ->where('item_classification', '!=', Product::ClassificationService)
            ->whereKey($selectedProductId ?: -1)
            ->get();
        $stores = BranchStore::query()
            ->where('branch_id', $context['branch_id'])
            ->whereKey($selectedStoreId ?: -1)
            ->get();

        return view('modules.production.quality.form', compact('record', 'mode', 'runs', 'inspectionTypes', 'products', 'stores'));
    }

    public function select2(
        Request $request,
        string $lookup,
        DataTableSearchService $search,
        Select2ResponseService $select2,
    ): JsonResponse {
        $context = $this->requiredContext($request);
        $terms = $search->terms($request->input('q', $request->input('term')));

        return match ($lookup) {
            'runs' => response()->json($select2->paginated(
                tap(ProductionRun::query()
                    ->where('company_id', $context['company_id'])
                    ->where('financial_period_id', $context['financial_period_id'])
                    ->where('branch_id', $context['branch_id'])
                    ->whereIn('status', [ProductionRun::StatusRunning, ProductionRun::StatusHeld])
                    ->with(['product', 'stageSnapshot'])
                    ->latest('id'), fn ($query) => $search->applyMultiTermSearch($query, $terms, ['text' => ['run_number']])),
                $request,
                fn (ProductionRun $run): array => [
                    'id' => (string) $run->getKey(),
                    'text' => collect([
                        $run->run_number,
                        $run->product?->name,
                        $run->stageSnapshot?->stage_name,
                        __('production_execution.statuses.'.$run->status),
                    ])->filter()->implode(' — '),
                ],
            )),
            'products' => response()->json($select2->paginated(
                tap(Product::query()
                    ->forCompany($context['company_id'])
                    ->active()
                    ->nonService()
                    ->orderBy('name'), fn ($query) => $search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'name', 'barcode']])),
                $request,
                fn (Product $product): array => ['id' => (string) $product->getKey(), 'text' => trim($product->doc_num.' — '.$product->name)],
            )),
            'stores' => response()->json($select2->paginated(
                tap(BranchStore::query()
                    ->where('branch_id', $context['branch_id'])
                    ->orderBy('position')
                    ->orderBy('name'), fn ($query) => $search->applyMultiTermSearch($query, $terms, ['text' => ['name']])),
                $request,
                fn (BranchStore $store): array => ['id' => (string) $store->getKey(), 'text' => (string) $store->name],
            )),
            'inspection-types' => response()->json($select2->paginated(
                tap(QualityInspectionType::query()
                    ->where('company_id', $context['company_id'])
                    ->where('is_active', true)
                    ->orderBy('name'), fn ($query) => $search->applyMultiTermSearch($query, $terms, ['text' => ['code', 'name']])),
                $request,
                fn (QualityInspectionType $type): array => ['id' => (string) $type->getKey(), 'text' => trim($type->code.' — '.$type->name)],
            )),
            default => abort(404),
        };
    }

    public function store(StoreProductionQualityInspectionRequest $request): RedirectResponse
    {
        try {
            $run = $request->input('subject_type') === ProductionQualityInspection::SubjectProductionRun
                ? $this->scopedRun($request, $request->integer('production_run_id'))
                : null;
            $inspection = $this->workflow->create($run, $request->validated());

            return $this->redirectAfterSave($request, $inspection, __('production_execution.messages.quality_created'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['quality' => $exception->getMessage()]);
        }
    }

    public function show(Request $request, int $inspection): View
    {
        $record = $this->scopedInspection($request, $inspection)->load([
            'run.order',
            'run.product',
            'product',
            'branchStore',
            'stageSnapshot',
            'qualityType',
            'results',
            'parentInspection',
            'reinspections',
            'reports.submittedBy',
            'stockHold.holdInventoryDocument',
            'stockHold.dispositionInventoryDocument',
            'maintenanceRequest.workOrder',
        ]);

        $snapshotCheckpoints = data_get($record->inspection_plan_snapshot, 'checkpoints');
        $checkpoints = (is_array($snapshotCheckpoints)
            ? collect($snapshotCheckpoints)->map(fn (array $checkpoint): object => (object) $checkpoint)
            : DB::table('quality_checkpoints')
                ->when($record->quality_inspection_type_id !== null, fn ($query) => $query
                    ->where('company_id', $record->company_id)
                    ->where('quality_inspection_type_id', $record->quality_inspection_type_id), fn ($query) => $query->whereRaw('1 = 0'))
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->orderBy('sequence')
                ->get(['id', 'code', 'name', 'name_ar', 'acceptance_criteria', 'measurement_unit', 'response_type', 'is_required']))
            ->map(function (object $checkpoint) use ($record): object {
                $checkpoint->id = (int) $checkpoint->id;
                $checkpoint->is_required = (bool) $checkpoint->is_required;
                $checkpoint->existing_result = $record->results->firstWhere('quality_checkpoint_id', $checkpoint->id);

                return $checkpoint;
            });
        $checkpointNames = $checkpoints->keyBy('id');
        if ($record->results->isNotEmpty()) {
            $missingCheckpointNames = DB::table('quality_checkpoints')
                ->whereIn('id', $record->results->pluck('quality_checkpoint_id')->diff($checkpointNames->keys()))
                ->get(['id', 'code', 'name', 'name_ar', 'acceptance_criteria', 'measurement_unit']);
            $checkpointNames = $checkpointNames->merge($missingCheckpointNames->keyBy('id'));
        }

        $rootId = $record->root_inspection_id ?? $record->getKey();
        $inspectionChain = ProductionQualityInspection::query()
            ->where('company_id', $record->company_id)
            ->where('financial_period_id', $record->financial_period_id)
            ->where('branch_id', $record->branch_id)
            ->where(fn ($query) => $query->whereKey($rootId)->orWhere('root_inspection_id', $rootId))
            ->orderBy('version')
            ->get();

        $evidence = collect($record->evidence ?? [])->map(function (mixed $file, int $index) use ($record): array {
            $file = is_array($file) ? $file : [];
            $mimeType = (string) ($file['mime_type'] ?? 'application/octet-stream');

            return [
                'name' => (string) ($file['original_name'] ?? __('production_execution.quality.attachment_number', ['number' => $index + 1])),
                'mime_type' => $mimeType,
                'is_image' => str_starts_with($mimeType, 'image/'),
                'url' => route('admin.production.quality.evidence', [$record->getKey(), $index]),
                'captured_at' => filled($file['captured_at'] ?? null) ? CarbonImmutable::parse($file['captured_at'])->format('Y-m-d H:i:s') : null,
            ];
        });

        return view('modules.production.quality.show', [
            ...compact('record', 'checkpoints', 'checkpointNames', 'evidence', 'inspectionChain'),
            'numbers' => $this->numbers,
        ]);
    }

    public function addReport(StoreProductionQualityInspectionReportRequest $request, int $inspection): RedirectResponse
    {
        $storedEvidence = collect();

        try {
            $record = $this->scopedInspection($request, $inspection);
            $data = $request->validated();
            if ($request->hasFile('evidence_files')) {
                $storedEvidence = $this->storeEvidence($request->file('evidence_files'), 'production-quality/reports');
                $data['evidence'] = $storedEvidence->all();
            }
            unset($data['evidence_files']);
            $this->workflow->addReport($record, $data);

            return redirect()->route('admin.production.quality.show', $record->getKey())
                ->with('success', __('production_execution.messages.quality_report_added'));
        } catch (DomainException $exception) {
            $this->deleteEvidence($storedEvidence);

            return back()->withInput()->withErrors(['quality' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->deleteEvidence($storedEvidence);

            throw $exception;
        }
    }

    public function evidence(Request $request, int $inspection, int $evidence): StreamedResponse
    {
        $record = $this->scopedInspection($request, $inspection);
        $file = collect($record->evidence ?? [])->get($evidence);

        abort_unless(is_array($file), 404);

        $disk = (string) ($file['disk'] ?? 'public');
        $path = (string) ($file['path'] ?? '');
        abort_if($path === '' || ! Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->response(
            $path,
            (string) ($file['original_name'] ?? basename($path)),
            ['Content-Disposition' => 'inline'],
        );
    }

    public function reportEvidence(Request $request, int $inspection, int $report, int $evidence): StreamedResponse
    {
        $record = $this->scopedInspection($request, $inspection);
        $reportRecord = ProductionQualityInspectionReport::query()
            ->where('quality_inspection_id', $record->getKey())
            ->findOrFail($report);
        $file = collect($reportRecord->evidence ?? [])->get($evidence);

        abort_unless(is_array($file), 404);

        return $this->evidenceResponse($file);
    }

    public function export(Request $request): BinaryFileResponse
    {
        [, $inspections] = $this->qualityReport($request);

        return Excel::download(new ProductionQualityReportExport($inspections), 'production-quality-'.now()->format('Ymd-His').'.xlsx');
    }

    public function print(Request $request): Response
    {
        [$context, $inspections] = $this->qualityReport($request);
        $company = Company::query()->findOrFail($context['company_id']);

        return $this->pdf->stream('reports.production.quality', [
            'title' => __('production_execution.quality.report_title'),
            'inspections' => $inspections,
            'numbers' => $this->numbers,
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
        ], 'production-quality-report.pdf');
    }

    public function printInspection(Request $request, int $inspection): Response
    {
        $record = $this->scopedInspection($request, $inspection)->load([
            'run.order', 'run.product', 'product', 'branchStore', 'stageSnapshot',
            'qualityType', 'results', 'reports.submittedBy',
        ]);
        $company = Company::query()->findOrFail($record->company_id);
        $snapshotCheckpoints = data_get($record->inspection_plan_snapshot, 'checkpoints');
        $checkpointNames = is_array($snapshotCheckpoints)
            ? collect($snapshotCheckpoints)->map(fn (array $checkpoint): object => (object) $checkpoint)->keyBy('id')
            : DB::table('quality_checkpoints')
                ->whereIn('id', $record->results->pluck('quality_checkpoint_id'))
                ->get(['id', 'code', 'name', 'name_ar'])
                ->keyBy('id');

        return $this->pdf->stream('reports.production.quality-inspection', [
            'title' => __('production_execution.fields.inspection').' — '.$record->doc_num,
            'record' => $record,
            'checkpointNames' => $checkpointNames,
            'numbers' => $this->numbers,
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
        ], str('quality-inspection-'.$record->doc_num)->slug().'.pdf');
    }

    public function receive(Request $request, int $inspection): JsonResponse
    {
        return $this->workflowAction($request, $inspection, fn (ProductionQualityInspection $record) => $this->workflow->receive($record));
    }

    public function createMaintenanceRequest(
        Request $request,
        int $inspection,
        MaintenanceWorkflowService $maintenance,
    ): JsonResponse {
        try {
            $record = $this->scopedInspection($request, $inspection)->load(['run', 'reports']);
            $hasRecordedProblem = $record->result === 'failed'
                || in_array($record->disposition, ['hold', 'rework', 'scrap', 'return'], true)
                || $record->reports->contains(fn (ProductionQualityInspectionReport $report): bool => $report->result === 'failed'
                    || in_array($report->disposition, ['hold', 'rework', 'scrap', 'return'], true));
            if (! $record->run || ! $hasRecordedProblem) {
                throw new DomainException(__('production_execution.messages.quality_maintenance_requires_problem'));
            }
            $maintenanceRequest = $maintenance->reportBreakdown([
                'quality_inspection_id' => $record->getKey(),
                'production_run_id' => $record->production_run_id,
                'fixed_asset_id' => $record->run?->fixed_asset_id,
                'production_mold_id' => $record->run?->production_mold_id,
                'request_type' => 'inspection',
                'priority' => $record->result === 'failed' ? 'high' : 'normal',
                'is_machine_stopped' => $record->run?->status === ProductionRun::StatusHeld,
                'symptoms' => collect([
                    $record->defect_code,
                    $record->notes,
                    __('production_execution.quality.maintenance_source', ['number' => $record->doc_num]),
                ])->filter()->implode(' — '),
            ]);

            return response()->json([
                'success' => true,
                'message' => __('production_execution.messages.quality_maintenance_request_created'),
                'redirect_url' => route('admin.maintenance.orders.create', ['request' => $maintenanceRequest->doc_num]),
            ]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function start(Request $request, int $inspection): JsonResponse
    {
        return $this->workflowAction($request, $inspection, fn (ProductionQualityInspection $record) => $this->workflow->start($record));
    }

    public function submit(SubmitProductionQualityInspectionRequest $request, int $inspection): RedirectResponse
    {
        $storedEvidence = collect();

        try {
            $record = $this->scopedInspection($request, $inspection);
            $data = $request->validated();
            if ($request->hasFile('evidence_files')) {
                $storedEvidence = $this->storeEvidence($request->file('evidence_files'), 'production-quality');
                $data['evidence'] = $storedEvidence->all();
            }
            unset($data['evidence_files']);
            $this->workflow->submit($record, $data);

            return redirect()->route('admin.production.quality.show', $record->getKey())
                ->with('success', __('production_execution.messages.quality_submitted'));
        } catch (DomainException $exception) {
            $this->deleteEvidence($storedEvidence);

            return back()->withInput()->withErrors(['quality' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->deleteEvidence($storedEvidence);

            throw $exception;
        }
    }

    public function approve(Request $request, int $inspection): JsonResponse
    {
        return $this->review($request, $inspection, true);
    }

    public function reject(Request $request, int $inspection): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->review($request, $inspection, false, $data['reason']);
    }

    public function close(CloseProductionQualityInspectionRequest $request, int $inspection): JsonResponse
    {
        return $this->workflowAction(
            $request,
            $inspection,
            fn (ProductionQualityInspection $record) => $this->workflow->close($record, $request->validated('close_notes')),
        );
    }

    public function reinspect(Request $request, int $inspection): JsonResponse
    {
        try {
            $record = $this->scopedInspection($request, $inspection);
            $reinspection = $this->workflow->reinspect($record);

            return response()->json([
                'success' => true,
                'message' => __('production_execution.messages.quality_reinspection_created'),
                'redirect_url' => route('admin.production.quality.show', $reinspection->getKey()),
            ]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function review(Request $request, int $inspection, bool $approved, ?string $reason = null): JsonResponse
    {
        try {
            $record = $this->scopedInspection($request, $inspection);
            $record = $this->workflow->review($record, $approved, $reason);

            return response()->json(['success' => true, 'status' => $record->status, 'message' => __('production_execution.messages.quality_reviewed')]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function workflowAction(Request $request, int $inspection, callable $operation): JsonResponse
    {
        try {
            $record = $operation($this->scopedInspection($request, $inspection));

            return response()->json(['success' => true, 'status' => $record->status, 'message' => __('production_execution.messages.operation_completed')]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function scopedInspection(Request $request, int $inspection): ProductionQualityInspection
    {
        $context = $this->context->snapshot($request);

        return ProductionQualityInspection::query()
            ->when($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], fn ($query) => $query
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->findOrFail($inspection);
    }

    private function scopedTrashedInspection(Request $request, int $inspection): ProductionQualityInspection
    {
        $context = $this->requiredContext($request);

        return ProductionQualityInspection::onlyTrashed()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->findOrFail($inspection);
    }

    private function redirectAfterSave(Request $request, ProductionQualityInspection $record, string $message): RedirectResponse
    {
        $action = $request->string('submit_action')->trim()->toString();
        $route = match ($action) {
            'save', 'save_view' => 'admin.production.quality.show',
            'save_edit' => 'admin.production.quality.edit',
            default => 'admin.production.quality.index',
        };
        $parameters = in_array($action, ['save', 'save_view', 'save_edit'], true) ? [$record->getKey()] : [];

        return redirect()->route($route, $parameters)->with('success', $message);
    }

    private function scopedRun(Request $request, int $run): ProductionRun
    {
        $context = $this->requiredContext($request);

        return ProductionRun::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->findOrFail($run);
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('production_execution.messages.operating_context_required'));

        return ['company_id' => (int) $context['company_id'], 'financial_period_id' => (int) $context['financial_period_id'], 'branch_id' => (int) $context['branch_id']];
    }

    /** @return array{0: array<string, mixed>, 1: Collection} */
    private function qualityReport(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('production_execution.messages.operating_context_required'));
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'production_run_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', Rule::in([
                ProductionQualityInspection::SubjectProductionRun,
                ProductionQualityInspection::SubjectProduct,
                ProductionQualityInspection::SubjectInventoryStock,
            ])],
            'status' => ['nullable', Rule::in([
                ProductionQualityInspection::StatusDraft,
                ProductionQualityInspection::StatusReceived,
                ProductionQualityInspection::StatusInProgress,
                ProductionQualityInspection::StatusSubmitted,
                ProductionQualityInspection::StatusApproved,
                ProductionQualityInspection::StatusRejected,
                ProductionQualityInspection::StatusClosed,
            ])],
            'result' => ['nullable', Rule::in(['pending', 'passed', 'failed', 'conditional'])],
            'disposition' => ['nullable', Rule::in(['release', 'hold', 'rework', 'scrap', 'return'])],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query
                ->where('company_id', $context['company_id'])
                ->whereNull('deleted_at'))],
            'branch_store_id' => ['nullable', 'integer', Rule::exists('branch_stores', 'id')->where(fn ($query) => $query
                ->where('branch_id', $context['branch_id'])
                ->whereNull('deleted_at'))],
        ]);
        $inspections = $this->reports->qualityInspections((int) $context['company_id'], [
            'financial_period_id' => (int) $context['financial_period_id'],
            'branch_id' => (int) $context['branch_id'],
            'from' => isset($filters['from']) ? CarbonImmutable::parse($filters['from'])->toDateString() : null,
            'to' => isset($filters['to']) ? CarbonImmutable::parse($filters['to'])->toDateString() : null,
            'production_run_id' => $filters['production_run_id'] ?? null,
            'subject_type' => $filters['subject_type'] ?? null,
            'quality_status' => $filters['status'] ?? null,
            'result' => $filters['result'] ?? null,
            'disposition' => $filters['disposition'] ?? null,
            'product_id' => $filters['product_id'] ?? null,
            'branch_store_id' => $filters['branch_store_id'] ?? null,
        ]);

        return [$context, $inspections];
    }

    /** @param array<int, UploadedFile> $files */
    private function storeEvidence(array $files, string $directory): \Illuminate\Support\Collection
    {
        return collect($files)->map(function ($file) use ($directory): array {
            return [
                'disk' => 'public',
                'path' => $file->store($directory, 'public'),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'captured_at' => now()->toIso8601String(),
                'uploaded_by' => auth()->id(),
            ];
        });
    }

    private function deleteEvidence(\Illuminate\Support\Collection $files): void
    {
        $files->each(fn (array $file) => Storage::disk($file['disk'])->delete($file['path']));
    }

    /** @param array<string, mixed> $file */
    private function evidenceResponse(array $file): StreamedResponse
    {
        $disk = (string) ($file['disk'] ?? 'public');
        $path = (string) ($file['path'] ?? '');
        abort_if($path === '' || ! Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->response(
            $path,
            (string) ($file['original_name'] ?? basename($path)),
            ['Content-Disposition' => 'inline'],
        );
    }
}
