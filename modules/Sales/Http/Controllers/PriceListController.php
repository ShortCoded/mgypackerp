<?php

namespace Modules\Sales\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Currency;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\SettingService;
use Modules\Sales\DataTables\PriceListsDataTable;
use Modules\Sales\Exports\PriceListExport;
use Modules\Sales\Http\Requests\BulkDeletePriceListsRequest;
use Modules\Sales\Http\Requests\IncreasePriceListPercentageRequest;
use Modules\Sales\Http\Requests\StorePriceListRequest;
use Modules\Sales\Http\Requests\UpdatePriceListRequest;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\PriceList;
use Modules\Sales\Services\PriceListReportData;
use Modules\Sales\Services\PriceListService;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PriceListController extends Controller
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly PriceListService $service,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(): View
    {
        return view('modules.sales.price-lists.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.sales.price-lists.index'),
        ]);
    }

    public function data(Request $request, PriceListsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(Request $request): View
    {
        return $this->form($request);
    }

    public function store(StorePriceListRequest $request): RedirectResponse
    {
        $cloning = $request->filled('clone_source_token');
        $this->authorizeSubmitAction($request, $this->submitAction($request), $cloning);
        $companyId = $this->companies->requireCompanyId($request);
        $cloneSource = $this->cloneSourceFromRequest($request, $companyId);
        $record = $this->service->create($request->validated(), $companyId, $cloneSource);

        if ($cloneSource instanceof PriceList) {
            $request->session()->forget($this->cloneSourceSessionKey(
                $companyId,
                $request->string('clone_source_token')->trim()->toString(),
            ));
        }

        return $this->redirectAfterSave($request, $record, creating: true, cloned: $cloneSource instanceof PriceList);
    }

    public function show(Request $request, PriceList $priceList): View
    {
        abort_if($priceList->trashed() && ! $request->user()?->can('price_lists.view_trashed'), 404);

        return $this->form($request, $priceList, true);
    }

    public function print(Request $request, PriceList $priceList): RedirectResponse
    {
        $this->authorizeOutput($request, $priceList);

        return redirect()->route('admin.sales.price-lists.pdf', $priceList);
    }

    public function pdf(Request $request, PriceList $priceList, PriceListReportData $reportData, CompanyPrintIdentityService $printIdentity, ReportPdfService $pdf): Response
    {
        return $this->pdfResponse($request, $priceList, $reportData, $printIdentity, $pdf);
    }

    public function exportXlsx(Request $request, PriceList $priceList, PriceListReportData $reportData): BinaryFileResponse
    {
        return $this->export($request, $priceList, $reportData, false);
    }

    public function exportCsv(Request $request, PriceList $priceList, PriceListReportData $reportData): BinaryFileResponse
    {
        return $this->export($request, $priceList, $reportData, true);
    }

    public function edit(Request $request, PriceList $priceList): View
    {
        return $this->form($request, $priceList);
    }

    public function update(UpdatePriceListRequest $request, PriceList $priceList): RedirectResponse
    {
        $this->authorizeSubmitAction($request, $this->submitAction($request));
        $record = $this->service->update($priceList, $request->validated(), $this->companies->requireCompanyId($request));

        return $this->redirectAfterSave($request, $record);
    }

    public function destroy(Request $request, PriceList $priceList): JsonResponse|RedirectResponse
    {
        $this->service->delete($priceList, $this->companies->requireCompanyId($request));

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => __('price_lists.messages.deleted')]);
        }

        return redirect()->route('admin.sales.price-lists.index')->with('success', __('price_lists.messages.deleted'));
    }

    public function bulkDelete(BulkDeletePriceListsRequest $request): JsonResponse
    {
        $deleted = $this->service->bulkDelete(
            $request->validated('doc_nums'),
            $this->companies->requireCompanyId($request),
        );

        return response()->json([
            'success' => true,
            'message' => __('price_lists.messages.bulk_deleted', ['count' => $deleted]),
            'data' => ['deleted' => $deleted],
        ]);
    }

    public function restore(Request $request, PriceList $priceList): JsonResponse
    {
        try {
            $this->service->restore($priceList, $this->companies->requireCompanyId($request));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('price_lists.messages.restored')]);
    }

    public function history(Request $request, PriceList $priceList): View
    {
        $this->authorizeOutput($request, $priceList);

        $activity = $this->getPriceListActivity($priceList);

        return view('modules.sales.price-lists.history', [
            'record' => $priceList,
            'activity' => $activity,
            'breadcrumbs' => [...$this->breadcrumbs->forMenuRoute('admin.sales.price-lists.index'), [
                'label' => __('price_lists.history'),
                'active' => true,
            ]],
        ]);
    }

    private function getPriceListActivity(PriceList $priceList): Collection
    {
        $log = Activity::query()
            ->with('causer')
            ->where('subject_type', $priceList->getMorphClass())
            ->where('subject_id', $priceList->getKey())
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return $log;
    }

    public function clone(Request $request, PriceList $priceList): View
    {
        $companyId = $this->companies->requireCompanyId($request);
        abort_unless((int) $priceList->company_id === $companyId, 404);

        $cloneSourceToken = (string) Str::uuid();
        $request->session()->put(
            $this->cloneSourceSessionKey($companyId, $cloneSourceToken),
            $priceList->doc_num,
        );

        return $this->form($request, $priceList, clone: true, cloneSourceToken: $cloneSourceToken);
    }

    public function increaseByPercentage(IncreasePriceListPercentageRequest $request, PriceList $priceList): JsonResponse
    {
        try {
            $record = $this->service->increaseByPercentage(
                $priceList,
                $request->validated('percentage'),
                $this->companies->requireCompanyId($request),
            );
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('price_lists.messages.increased'),
            'data' => [
                'doc_num' => $record->doc_num,
                'lines' => $record->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'product' => $line->product?->name,
                    'unit_price' => $line->unit_price,
                ])->all(),
            ],
        ]);
    }

    public function review(Request $request, PriceList $priceList): JsonResponse
    {
        $record = $this->service->review($priceList, $this->companies->requireCompanyId($request));

        return response()->json([
            'success' => true,
            'message' => __('price_lists.messages.reviewed'),
            'data' => ['reviewed_at' => $record->reviewed_at?->toIso8601String()],
        ]);
    }

    public function approve(Request $request, PriceList $priceList): JsonResponse
    {
        try {
            $record = $this->service->approve($priceList, $this->companies->requireCompanyId($request));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('price_lists.messages.approved'),
            'data' => ['approved_at' => $record->approved_at?->toIso8601String()],
        ]);
    }

    private function form(Request $request, ?PriceList $record = null, bool $readOnly = false, bool $clone = false, ?string $cloneSourceToken = null): View
    {
        $companyId = $this->companies->requireCompanyId($request);
        abort_if($record && (int) $record->company_id !== $companyId, 404);
        $record?->load(['customer', 'currency', 'lines.product', 'reviewedBy', 'approvedBy', 'createdBy', 'updatedBy', 'deletedBy', 'restoredBy']);
        $selectedCustomerDocNum = old('customer_doc_num', $record?->customer?->doc_num);

        return view('modules.sales.price-lists.form', [
            'record' => $record, 'readOnly' => $readOnly, 'clone' => $clone, 'cloneSourceToken' => $cloneSourceToken,
            'companyId' => $companyId,
            'selectedCustomers' => Customer::query()->forCompany($companyId)->where('doc_num', $selectedCustomerDocNum)->get(),
            'currencies' => Currency::query()->forCompany($companyId)->active()->orderByDesc('is_main')->orderBy('code')->get(),
            'metadata' => $this->metadata($record),
            'breadcrumbs' => [...$this->breadcrumbs->forMenuRoute('admin.sales.price-lists.index'), ['label' => $clone ? __('price_lists.clone') : ($record?->doc_num ?? __('price_lists.create')), 'active' => true]],
        ]);
    }

    /** @return array<string, string|null> */
    private function metadata(?PriceList $record): array
    {
        if (! $record instanceof PriceList) {
            return [];
        }

        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($record->createdBy),
            'created_at' => $settings->formatDateTime($record->created_at, ''),
            'updated_by' => $this->auditUserLabel($record->updatedBy),
            'updated_at' => $settings->formatDateTime($record->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($record->deletedBy),
            'deleted_at' => $settings->formatDateTime($record->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($record->restoredBy),
            'restored_at' => $settings->formatDateTime($record->restored_at, ''),
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        return $user instanceof User ? trim(implode(' / ', array_filter([$user->name, $user->doc_num]))) : null;
    }

    private function redirectAfterSave(Request $request, PriceList $record, bool $creating = false, bool $cloned = false): RedirectResponse
    {
        $action = $this->submitAction($request);

        if ($cloned && $action === 'save_new' && ! $request->user()?->can('price_lists.create')) {
            return redirect()->route('admin.sales.price-lists.clone', $record)->with('success', __('price_lists.messages.cloned'));
        }

        $route = match ($action) {
            'save_view' => 'admin.sales.price-lists.show',
            'save_edit' => 'admin.sales.price-lists.edit',
            'save_back' => 'admin.sales.price-lists.index',
            'save_new' => 'admin.sales.price-lists.create',
            default => $creating ? 'admin.sales.price-lists.show' : 'admin.sales.price-lists.edit',
        };
        $parameters = in_array($action, ['save_back', 'save_new'], true) ? [] : [$record];

        return redirect()->route($route, $parameters)->with(
            'success',
            $cloned ? __('price_lists.messages.cloned') : ($creating ? __('price_lists.messages.created') : __('price_lists.messages.updated')),
        );
    }

    private function cloneSourceFromRequest(StorePriceListRequest $request, int $companyId): ?PriceList
    {
        $token = $request->string('clone_source_token')->trim()->toString();

        if ($token === '') {
            return null;
        }

        $sourceDocNum = (string) $request->session()->get($this->cloneSourceSessionKey($companyId, $token), '');

        if ($sourceDocNum === '') {
            throw ValidationException::withMessages([
                'clone_source_token' => __('price_lists.messages.clone_not_allowed'),
            ]);
        }

        $source = PriceList::query()
            ->forCompany($companyId)
            ->where('doc_num', $sourceDocNum)
            ->first();

        if (! $source instanceof PriceList) {
            throw ValidationException::withMessages([
                'clone_source_token' => __('price_lists.messages.clone_not_allowed'),
            ]);
        }

        return $source;
    }

    private function cloneSourceSessionKey(int $companyId, string $token): string
    {
        return "price_lists.clone_sources.{$companyId}.{$token}";
    }

    private function submitAction(Request $request): string
    {
        $action = $request->string('submit_action')->trim()->toString();

        return in_array($action, ['save', 'save_view', 'save_edit', 'save_back', 'save_new'], true) ? $action : 'save';
    }

    private function authorizeSubmitAction(Request $request, string $action, bool $cloning = false): void
    {
        $permission = match ($action) {
            'save_view', 'save_back' => 'price_lists.view',
            'save_edit' => 'price_lists.edit',
            'save_new' => $cloning ? 'price_lists.clone' : 'price_lists.create',
            default => null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403);
    }

    private function authorizeOutput(Request $request, PriceList $priceList): void
    {
        abort_unless((int) $priceList->company_id === $this->companies->requireCompanyId($request), 404);
        abort_if($priceList->trashed() && ! $request->user()?->can('price_lists.view_trashed'), 404);
    }

    private function pdfResponse(
        Request $request,
        PriceList $priceList,
        PriceListReportData $reportData,
        CompanyPrintIdentityService $printIdentity,
        ReportPdfService $pdf,
    ): Response {
        $this->authorizeOutput($request, $priceList);
        $report = $reportData->build($priceList);

        return $pdf->stream('reports.sales.price-list', [
            'title' => __('price_lists.print_title'),
            'documentHeaderTitle' => __('price_lists.print_title'),
            'printIdentityPolicy' => 'report',
            'companyPrintIdentity' => $printIdentity->forCompany($priceList->company),
            'record' => $priceList,
            'report' => $report,
        ], str('price-list-'.$priceList->doc_num)->slug().'.pdf', 'L');
    }

    private function export(Request $request, PriceList $priceList, PriceListReportData $reportData, bool $forCsv): BinaryFileResponse
    {
        $this->authorizeOutput($request, $priceList);
        $report = $reportData->build($priceList);
        $extension = $forCsv ? 'csv' : 'xlsx';

        return Excel::download(
            new PriceListExport($report, $forCsv),
            str('price-list-'.$priceList->doc_num)->slug().'.'.$extension,
            $forCsv ? ExcelWriter::CSV : ExcelWriter::XLSX,
        );
    }

    public function select2PriceLists(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canAny(['price_lists.view', 'inventory.reports.operational']), 403);

        $term = trim((string) $request->input('term', ''));
        $companyId = $this->companies->requireCompanyId($request);
        $priceLists = PriceList::query()
            ->where('company_id', $companyId)
            ->whereNotNull('approved_at')
            ->whereNull('deleted_at')
            ->when($term !== '', fn ($query) => $query->where('doc_num', 'like', "%{$term}%"))
            ->orderBy('doc_num')
            ->limit(100)
            ->get(['id', 'doc_num']);

        return response()->json($priceLists->map(fn (PriceList $pl) => [
            'id' => $pl->id,
            'doc_num' => $pl->doc_num,
            'text' => $pl->doc_num,
        ]));
    }
}
