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
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
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
    ) {}

    public function index(): View
    {
        return view('modules.production.quality.index', ['scope' => 'all']);
    }

    public function active(): View
    {
        return view('modules.production.quality.index', ['scope' => 'active']);
    }

    public function reportsIndex(): View
    {
        return view('modules.production.quality.reports');
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
        $context = $this->requiredContext($request);
        $runs = ProductionRun::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->whereIn('status', [ProductionRun::StatusRunning, ProductionRun::StatusHeld])
            ->with(['product', 'stageSnapshot'])
            ->latest('id')
            ->get();
        $inspectionTypes = QualityInspectionType::query()
            ->where('company_id', $context['company_id'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $products = Product::query()
            ->where('company_id', $context['company_id'])
            ->where('status', 'active')
            ->where('item_classification', '!=', Product::ClassificationService)
            ->orderBy('name')
            ->get();
        $stores = BranchStore::query()
            ->where('branch_id', $context['branch_id'])
            ->orderBy('position')
            ->get();

        return view('modules.production.quality.form', compact('runs', 'inspectionTypes', 'products', 'stores'));
    }

    public function store(StoreProductionQualityInspectionRequest $request): RedirectResponse
    {
        try {
            $run = $request->input('subject_type') === ProductionQualityInspection::SubjectProductionRun
                ? $this->scopedRun($request, $request->integer('production_run_id'))
                : null;
            $inspection = $this->workflow->create($run, $request->validated());

            return redirect()->route('admin.production.quality.show', $inspection->getKey())
                ->with('success', __('production_execution.messages.quality_created'));
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
        ]);

        $checkpoints = DB::table('quality_checkpoints')
            ->when($record->quality_inspection_type_id !== null, fn ($query) => $query
                ->where('company_id', $record->company_id)
                ->where('quality_inspection_type_id', $record->quality_inspection_type_id), fn ($query) => $query->whereRaw('1 = 0'))
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sequence')
            ->get(['id', 'code', 'name', 'name_ar', 'acceptance_criteria', 'measurement_unit', 'response_type', 'is_required'])
            ->map(function (object $checkpoint) use ($record): object {
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

        return view('modules.production.quality.show', compact('record', 'checkpoints', 'checkpointNames', 'evidence', 'inspectionChain'));
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
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
        ], 'production-quality-report.pdf');
    }

    public function receive(Request $request, int $inspection): JsonResponse
    {
        return $this->workflowAction($request, $inspection, fn (ProductionQualityInspection $record) => $this->workflow->receive($record));
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
        $inspections = $this->reports->qualityInspections((int) $context['company_id'], [
            'financial_period_id' => (int) $context['financial_period_id'],
            'branch_id' => (int) $context['branch_id'],
            'from' => $request->date('from')?->toDateString(),
            'to' => $request->date('to')?->toDateString(),
            'production_run_id' => $request->integer('production_run_id') ?: null,
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
