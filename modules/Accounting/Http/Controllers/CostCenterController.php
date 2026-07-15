<?php

namespace Modules\Accounting\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\DataTables\CostCentersDataTable;
use Modules\Accounting\Exports\CostCentersExport;
use Modules\Accounting\Http\Requests\BulkDeleteCostCentersRequest;
use Modules\Accounting\Http\Requests\StoreCostCenterRequest;
use Modules\Accounting\Http\Requests\UpdateCostCenterDocumentNumberSettingsRequest;
use Modules\Accounting\Http\Requests\UpdateCostCenterRequest;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\CostCenterDocumentNumberSettingsService;
use Modules\Accounting\Services\CostCenterSelect2Service;
use Modules\Accounting\Services\CostCenterService;
use Modules\Accounting\Services\CostCenterTreeReport;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\SettingService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class CostCenterController extends Controller
{
    public function __construct(
        private readonly CostCenterService $costCenters,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function index(CostCenterDocumentNumberSettingsService $settings): View
    {
        return view('modules.accounting.cost-centers.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.accounting.cost-centers.index'),
            'documentNumberSettings' => $settings->current(),
        ]);
    }

    public function data(Request $request, CostCentersDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function tree(Request $request, CostCenterTreeReport $report): JsonResponse
    {
        $rows = $report->rows($report->filtersFromRequest($request));

        return response()->json([
            'success' => true,
            'data' => $report->treeNodes($rows),
        ]);
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(string $costCenter): View
    {
        return $this->form('view', $this->resolveCostCenter($costCenter, withTrashed: true));
    }

    public function edit(string $costCenter): View
    {
        return $this->form('edit', $this->resolveCostCenter($costCenter));
    }

    public function clone(string $costCenter): View
    {
        $costCenter = $this->resolveCostCenter($costCenter);
        $cloneSourceToken = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($cloneSourceToken), $costCenter->doc_num);

        return $this->form('clone', $costCenter, $cloneSourceToken);
    }

    public function store(StoreCostCenterRequest $request): JsonResponse
    {
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'));
        $this->validateCloneSourceToken($request);

        $costCenter = $this->costCenters->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('cost_centers.messages.created'),
            ...$this->saveActionResponse($request, $costCenter, 'store'),
            'data' => [
                'doc_num' => $costCenter->doc_num,
                'doc_number' => $costCenter->doc_number,
                'urls' => $this->costCenterUrls($costCenter),
            ],
        ]);
    }

    public function update(UpdateCostCenterRequest $request, string $costCenter): JsonResponse
    {
        $submitAction = $this->submitAction($request);
        $this->authorizeSubmitAction($request, $submitAction);
        $costCenter = $this->resolveCostCenter($costCenter);
        $previousDocNum = $costCenter->doc_num;
        $previousDocNumber = $costCenter->doc_number;
        $result = $this->costCenters->update($costCenter, $request->validated());

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
                'submit_action' => $submitAction,
            ]);
        }

        /** @var CostCenter $record */
        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('cost_centers.messages.updated'),
            ...$this->saveActionResponse($request, $record, 'update'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'previous_doc_num' => $previousDocNum,
                'previous_doc_number' => $previousDocNumber,
                'urls' => $this->costCenterUrls($record),
            ],
        ]);
    }

    public function destroy(string $costCenter): JsonResponse
    {
        try {
            $this->costCenters->delete($this->resolveCostCenter($costCenter));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('cost_centers.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteCostCentersRequest $request): JsonResponse
    {
        try {
            $deleted = $this->costCenters->bulkDelete($request->validated('doc_nums'));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('cost_centers.messages.bulk_deleted', ['count' => $deleted])]);
    }

    public function restore(string $costCenter): JsonResponse
    {
        try {
            $this->costCenters->restore($this->resolveCostCenter($costCenter, withTrashed: true));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('cost_centers.messages.restored')]);
    }

    public function updateDocumentNumberSettings(UpdateCostCenterDocumentNumberSettingsRequest $request, CostCenterDocumentNumberSettingsService $settings): JsonResponse
    {
        $settings->update($request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json(['success' => true, 'message' => __('cost_centers.document_number_settings.updated_successfully')]);
    }

    public function exportExcel(Request $request, CostCenterTreeReport $report): BinaryFileResponse
    {
        return Excel::download(new CostCentersExport($report, $report->filtersFromRequest($request)), 'cost-centers.xlsx');
    }

    public function exportCsv(Request $request, CostCenterTreeReport $report): BinaryFileResponse
    {
        return Excel::download(new CostCentersExport($report, $report->filtersFromRequest($request)), 'cost-centers.csv', ExcelFormat::CSV);
    }

    public function exportPdf(Request $request, ReportPdfService $pdf, CostCenterTreeReport $report): Response
    {
        $filters = $report->filtersFromRequest($request);
        $rows = $report->rows($filters);

        return $pdf->stream('reports.cost-centers', [
            'title' => __('cost_centers.tree_title'),
            'headings' => $report->headings(),
            'rows' => $report->pdfRows($rows),
            'filters' => $report->filterSummary($filters),
        ], 'cost-centers.pdf');
    }

    public function select2CostCenters(Request $request, CostCenterSelect2Service $select2): JsonResponse
    {
        return response()->json($select2->costCenters($request));
    }

    public function nextCode(Request $request): JsonResponse
    {
        $companyId = $this->companies->requireCompanyId($request);
        $parentDocNum = $request->string('parent_doc_num')->trim()->toString();
        $parent = null;

        if ($parentDocNum !== '') {
            $parent = CostCenter::query()
                ->forCompany($companyId)
                ->active()
                ->group()
                ->where('doc_num', $parentDocNum)
                ->first();

            if (! $parent instanceof CostCenter) {
                return response()->json([
                    'success' => false,
                    'message' => __('cost_centers.messages.parent_unavailable'),
                ], 422);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'cost_center_code' => $this->costCenters->nextCostCenterCode($parent, $companyId),
            ],
        ]);
    }

    private function form(string $mode, ?CostCenter $costCenter = null, ?string $cloneSourceToken = null): View
    {
        $costCenter?->loadMissing('parent');

        return view('modules.accounting.cost-centers.form', [
            'mode' => $mode,
            'costCenter' => $costCenter,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.accounting.cost-centers.store') : route('admin.accounting.cost-centers.update', $costCenter?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('cost_centers.document_number.control'),
            'metadata' => $this->metadata($costCenter),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.accounting.cost-centers.index', [['label' => __("cost_centers.{$mode}"), 'active' => true]]),
            'cloneSourceToken' => $cloneSourceToken,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function costCenterUrls(CostCenter $costCenter): array
    {
        return [
            'show' => route('admin.accounting.cost-centers.show', $costCenter->doc_num),
            'clone' => route('admin.accounting.cost-centers.clone', $costCenter->doc_num),
            'edit' => route('admin.accounting.cost-centers.edit', $costCenter->doc_num),
            'update' => route('admin.accounting.cost-centers.update', $costCenter->doc_num),
            'destroy' => route('admin.accounting.cost-centers.destroy', $costCenter->doc_num),
            'index' => route('admin.accounting.cost-centers.index'),
        ];
    }

    private function saveActionResponse(Request $request, CostCenter $costCenter, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $response = ['submit_action' => $action];

        $redirect = match ($action) {
            'save_view' => route('admin.accounting.cost-centers.show', $costCenter->doc_num),
            'save_edit' => route('admin.accounting.cost-centers.edit', $costCenter->doc_num),
            'save_back' => route('admin.accounting.cost-centers.index'),
            'save_new' => $operation === 'store' ? null : route('admin.accounting.cost-centers.create'),
            'save_clone' => route('admin.accounting.cost-centers.clone', $costCenter->doc_num),
            default => $operation === 'store' ? $this->redirectAfterStore($request, $costCenter) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';
        }

        return $response;
    }

    private function authorizeSubmitAction(Request $request, string $action, bool $cloning = false): void
    {
        $permission = match ($action) {
            'save_view' => 'cost_centers.view',
            'save_edit' => 'cost_centers.edit',
            'save_back' => 'cost_centers.view',
            'save_new' => $cloning ? 'cost_centers.clone' : 'cost_centers.create',
            'save_clone' => 'cost_centers.clone',
            default => $cloning ? 'cost_centers.clone' : null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('cost_centers.messages.action_forbidden'));
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';
        $allowedActions = ['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'];

        if ($creating && $action === 'save') {
            return 'save_new';
        }

        if (! $creating && in_array($action, ['save_new', 'save_edit'], true)) {
            return 'save';
        }

        if (! in_array($action, $allowedActions, true)) {
            return $creating ? 'save_new' : 'save';
        }

        return $action;
    }

    private function redirectAfterStore(Request $request, CostCenter $costCenter): string
    {
        if ($request->user()?->can('cost_centers.edit')) {
            return route('admin.accounting.cost-centers.edit', $costCenter->doc_num);
        }

        if ($request->user()?->can('cost_centers.view')) {
            return route('admin.accounting.cost-centers.show', $costCenter->doc_num);
        }

        return route('admin.accounting.cost-centers.index');
    }

    private function validateCloneSourceToken(StoreCostCenterRequest $request): void
    {
        $cloneSourceToken = $request->string('clone_source_token')->trim()->toString();

        if ($cloneSourceToken === '') {
            return;
        }

        abort_unless((bool) $request->user()?->can('cost_centers.clone'), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($cloneSourceToken), '');

        if ($sourceDocNum === '' || ! CostCenter::query()->forCompany($this->companies->requireCompanyId($request))->where('doc_num', $sourceDocNum)->exists()) {
            throw ValidationException::withMessages([
                'cost_center_code' => __('cost_centers.messages.clone_not_allowed'),
            ]);
        }
    }

    private function resolveCostCenter(string $docNum, bool $withTrashed = false): CostCenter
    {
        $query = $withTrashed ? CostCenter::withTrashed() : CostCenter::query();

        return $query
            ->forCompany($this->companies->requireCompanyId())
            ->where('doc_num', $docNum)
            ->firstOrFail();
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return 'cost_centers.clone_sources.'.$token;
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?CostCenter $costCenter): array
    {
        if (! $costCenter instanceof CostCenter) {
            return [
                'created_by' => null,
                'created_at' => null,
                'updated_by' => null,
                'updated_at' => null,
                'deleted_by' => null,
                'deleted_at' => null,
                'restored_by' => null,
                'restored_at' => null,
            ];
        }

        $users = User::query()
            ->whereIn('id', array_filter([$costCenter->created_by, $costCenter->updated_by, $costCenter->deleted_by, $costCenter->restored_by]))
            ->get(['id', 'name', 'doc_num'])
            ->keyBy('id');
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($users->get($costCenter->created_by)),
            'created_at' => $settings->formatDateTime($costCenter->created_at, ''),
            'updated_by' => $this->auditUserLabel($users->get($costCenter->updated_by)),
            'updated_at' => $settings->formatDateTime($costCenter->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($users->get($costCenter->deleted_by)),
            'deleted_at' => $settings->formatDateTime($costCenter->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($users->get($costCenter->restored_by)),
            'restored_at' => $settings->formatDateTime($costCenter->restored_at, ''),
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        return trim(implode(' / ', array_filter([$user->name, $user->doc_num])));
    }
}
