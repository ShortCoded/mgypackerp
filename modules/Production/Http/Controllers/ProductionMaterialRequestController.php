<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Production\DataTables\ProductionExecutionDataTable;
use Modules\Production\Http\Requests\StoreProductionMaterialRequest;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionMaterialRequestService;

class ProductionMaterialRequestController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly ProductionMaterialRequestService $service,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function index(Request $request, ProductionExecutionDataTable $dataTable): View|JsonResponse
    {
        $this->requiredContext($request);

        if ($request->expectsJson()) {
            return $dataTable->materialRequests($request);
        }

        return view('modules.production.material-requests.index');
    }

    public function create(Request $request): View
    {
        return $this->form($request);
    }

    public function show(Request $request, ProductionMaterialRequest $productionMaterialRequest): View
    {
        $this->assertProductionRequest($request, $productionMaterialRequest);

        return $this->form($request, $productionMaterialRequest, 'view');
    }

    public function edit(Request $request, ProductionMaterialRequest $productionMaterialRequest): View
    {
        $this->assertProductionRequest($request, $productionMaterialRequest);
        abort_unless($this->isEditable($productionMaterialRequest), 422, __('production_execution.messages.material_request_submitted_edit_only'));

        return $this->form($request, $productionMaterialRequest, 'edit');
    }

    public function clone(Request $request, ProductionMaterialRequest $productionMaterialRequest): View
    {
        $this->assertProductionRequest($request, $productionMaterialRequest);

        return $this->form($request, $productionMaterialRequest, 'clone');
    }

    public function store(StoreProductionMaterialRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $run = ProductionRun::query()->findOrFail($data['production_run_id']);
        $record = $this->guard(fn (): ProductionMaterialRequest => $this->service->create(
            $run,
            (int) $data['branch_store_id'],
            $this->quantities($data['lines'] ?? []),
            (bool) ($data['additional'] ?? false),
            $data['reason'] ?? null,
            $data['required_by_date'] ?? null,
        ));

        return redirect()->to($this->submitRedirectUrl($request, $record))
            ->with('success', __('production_execution.messages.material_request_created'));
    }

    public function update(StoreProductionMaterialRequest $request, ProductionMaterialRequest $productionMaterialRequest): RedirectResponse
    {
        $this->assertProductionRequest($request, $productionMaterialRequest);
        $data = $request->validated();
        $record = $this->guard(fn (): ProductionMaterialRequest => $this->service->update(
            $productionMaterialRequest,
            (int) $data['branch_store_id'],
            $this->quantities($data['lines'] ?? []),
            (bool) ($data['additional'] ?? false),
            $data['reason'] ?? null,
            $data['required_by_date'] ?? null,
        ));

        return redirect()->to($this->submitRedirectUrl($request, $record))
            ->with('success', __('production_execution.messages.material_request_updated'));
    }

    public function destroy(Request $request, ProductionMaterialRequest $productionMaterialRequest): JsonResponse|RedirectResponse
    {
        $this->assertProductionRequest($request, $productionMaterialRequest);
        $this->guard(fn () => $this->service->delete($productionMaterialRequest));

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => __('production_execution.messages.material_request_deleted')])
            : to_route('admin.production.material-requests.index')->with('success', __('production_execution.messages.material_request_deleted'));
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $context = $this->requiredContext($request);
        $docNums = $request->validate([
            'doc_nums' => ['required', 'array', 'min:1', 'max:100'],
            'doc_nums.*' => ['required', 'string', 'distinct'],
        ])['doc_nums'];

        DB::transaction(function () use ($context, $docNums): void {
            $records = ProductionMaterialRequest::query()
                ->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])
                ->whereIn('doc_num', $docNums)
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                $this->service->delete($record);
            }
        });

        return response()->json(['success' => true, 'message' => __('production_execution.messages.material_requests_bulk_deleted')]);
    }

    public function restore(Request $request, string $productionMaterialRequest): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        $record = ProductionMaterialRequest::onlyTrashed()
            ->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])
            ->where('doc_num', $productionMaterialRequest)
            ->firstOrFail();
        $record = $this->guard(fn (): ProductionMaterialRequest => $this->service->restore($record));

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => __('production_execution.messages.material_request_restored')])
            : to_route('admin.production.material-requests.show', $record)->with('success', __('production_execution.messages.material_request_restored'));
    }

    public function print(Request $request, ProductionMaterialRequest $productionMaterialRequest): Response
    {
        $this->assertProductionRequest($request, $productionMaterialRequest);
        $record = $productionMaterialRequest->load([
            'order.company', 'run.product', 'run.stageSnapshot', 'store', 'lines.product', 'lines.unit',
            'purchaseRequisition', 'inventoryDocuments',
        ]);

        return $this->pdf->stream('reports.production.material-request', [
            'title' => __('production_execution.print.material_request').' — '.$record->doc_num,
            'record' => $record,
            'companyPrintIdentity' => $record->order->print_identity_snapshot ?: $this->printIdentity->forCompany($record->order->company),
        ], str('production-material-request-'.$record->doc_num)->slug().'.pdf');
    }

    public function approve(Request $request, ProductionMaterialRequest $productionMaterialRequest): JsonResponse
    {
        $this->assertProductionRequest($request, $productionMaterialRequest);

        return $this->jsonGuard(fn () => $this->service->approve($productionMaterialRequest));
    }

    public function issue(Request $request, ProductionMaterialRequest $productionMaterialRequest): JsonResponse|RedirectResponse
    {
        $this->assertProductionRequest($request, $productionMaterialRequest);
        $numbers = app(NumericFormatService::class);
        $request->merge([
            'lines' => collect($request->input('lines', []))->map(function (mixed $line) use ($numbers): mixed {
                if (is_array($line) && array_key_exists('quantity', $line)) {
                    $line['quantity'] = $numbers->normalizeForValidation($line['quantity']);
                }

                return $line;
            })->all(),
        ]);
        $data = $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*.request_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gte:0'],
        ]);
        $document = $this->guard(fn () => $this->service->issue(
            $productionMaterialRequest,
            $this->issueQuantities($data['lines'] ?? []),
        ));

        return $request->expectsJson()
            ? response()->json([
                'success' => true,
                'status' => $productionMaterialRequest->fresh()->status,
                'doc_num' => $document->doc_num,
                'message' => __('production_execution.messages.material_issue_created'),
            ])
            : to_route('admin.production.material-requests.show', $productionMaterialRequest)
                ->with('success', __('production_execution.messages.material_issue_created_with_number', ['number' => $document->doc_num]));
    }

    public function allocateShortage(Request $request, ProductionMaterialRequest $productionMaterialRequest): JsonResponse
    {
        $this->assertProductionRequest($request, $productionMaterialRequest);

        return $this->jsonGuard(fn () => $this->service->allocateShortage($productionMaterialRequest));
    }

    private function form(Request $request, ?ProductionMaterialRequest $record = null, string $mode = 'create'): View
    {
        $context = $this->requiredContext($request);
        $record?->loadMissing(['run.requirements.product', 'run.requirements.unit', 'lines.product', 'lines.unit', 'store', 'inventoryDocuments']);
        $runs = ProductionRun::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->when($mode !== 'view', fn ($query) => $query->whereNotIn('status', [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled]))
            ->with(['requirements.product', 'requirements.unit', 'stageSnapshot'])
            ->latest()
            ->get();

        if ($record?->run && ! $runs->contains('id', $record->production_run_id)) {
            $runs->prepend($record->run);
        }

        $selectedRunId = old('production_run_id', $record?->production_run_id ?? $request->integer('run'));
        $selectedRun = $runs->firstWhere('id', (int) $selectedRunId);
        $remainingRequestableByRequirement = $selectedRun?->requirements
            ->mapWithKeys(fn ($requirement): array => [
                $requirement->getKey() => $this->service->remainingRequestableFor(
                    $requirement,
                    $mode === 'edit' ? $record?->getKey() : null,
                ),
            ]) ?? collect();

        return view('modules.production.material-requests.form', [
            'record' => $record,
            'mode' => $mode,
            'runs' => $runs,
            'selectedRun' => $selectedRun,
            'remainingRequestableByRequirement' => $remainingRequestableByRequirement,
            'stores' => BranchStore::query()->where('branch_id', $context['branch_id'])->orderBy('position')->get(),
        ]);
    }

    /** @param list<array<string, mixed>> $lines @return array<int, mixed> */
    private function quantities(array $lines): array
    {
        return collect($lines)
            ->filter(fn (array $line): bool => filled($line['quantity'] ?? null))
            ->mapWithKeys(fn (array $line): array => [$line['requirement_id'] => $line['quantity']])
            ->all();
    }

    /** @param list<array<string, mixed>> $lines @return array<int, mixed> */
    private function issueQuantities(array $lines): array
    {
        return collect($lines)
            ->filter(fn (array $line): bool => filled($line['quantity'] ?? null))
            ->mapWithKeys(fn (array $line): array => [(int) $line['request_line_id'] => $line['quantity']])
            ->all();
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('production_execution.messages.operating_context_required'));

        return [
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
            'branch_id' => (int) $context['branch_id'],
        ];
    }

    private function assertProductionRequest(Request $request, ProductionMaterialRequest $record): void
    {
        $context = $this->requiredContext($request);
        abort_unless(
            (int) $record->company_id === $context['company_id']
            && (int) $record->financial_period_id === $context['financial_period_id']
            && (int) $record->branch_id === $context['branch_id']
            && $record->production_run_id !== null,
            404,
        );
    }

    private function isEditable(ProductionMaterialRequest $record): bool
    {
        return ! $record->trashed()
            && $record->status === ProductionMaterialRequest::StatusSubmitted
            && ! $record->inventoryDocuments()->exists();
    }

    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['material_request' => $exception->getMessage()]);
        }
    }

    private function jsonGuard(callable $callback): JsonResponse
    {
        try {
            $record = $callback();

            return response()->json([
                'success' => true,
                'status' => $record->status ?? null,
                'doc_num' => $record->doc_num ?? null,
                'message' => __('production_execution.messages.operation_completed'),
            ]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function submitRedirectUrl(Request $request, ProductionMaterialRequest $record): string
    {
        return match ($request->string('submit_action')->trim()->toString() ?: 'save') {
            'save_view' => route('admin.production.material-requests.show', $record),
            'save_back' => route('admin.production.material-requests.index'),
            'save_clone' => route('admin.production.material-requests.clone', $record),
            'save', 'save_edit' => $request->user()?->can('production.material_requests.edit') && $this->isEditable($record)
                ? route('admin.production.material-requests.edit', $record)
                : route('admin.production.material-requests.show', $record),
            default => route('admin.production.material-requests.show', $record),
        };
    }
}
