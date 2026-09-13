<?php

namespace Modules\Sales\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\Currency;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Sales\DataTables\PriceListsDataTable;
use Modules\Sales\Http\Requests\BulkDeletePriceListsRequest;
use Modules\Sales\Http\Requests\StorePriceListRequest;
use Modules\Sales\Http\Requests\UpdatePriceListRequest;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\PriceList;
use Modules\Sales\Services\PriceListService;

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
        $this->authorizeSubmitAction($request, $this->submitAction($request));
        $record = $this->service->create($request->validated(), $this->companies->requireCompanyId($request));

        return $this->redirectAfterSave($request, $record, creating: true);
    }

    public function show(Request $request, PriceList $priceList): View
    {
        abort_if($priceList->trashed() && ! $request->user()?->can('price_lists.view_trashed'), 404);

        return $this->form($request, $priceList, true);
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

    private function form(Request $request, ?PriceList $record = null, bool $readOnly = false): View
    {
        $companyId = $this->companies->requireCompanyId($request);
        abort_if($record && (int) $record->company_id !== $companyId, 404);
        $record?->load(['customer', 'currency', 'lines.product']);
        $selectedCustomerDocNum = old('customer_doc_num', $record?->customer?->doc_num);

        return view('modules.sales.price-lists.form', [
            'record' => $record, 'readOnly' => $readOnly,
            'selectedCustomers' => Customer::query()->forCompany($companyId)->where('doc_num', $selectedCustomerDocNum)->get(),
            'currencies' => Currency::query()->forCompany($companyId)->active()->orderByDesc('is_main')->orderBy('code')->get(),
            'breadcrumbs' => [...$this->breadcrumbs->forMenuRoute('admin.sales.price-lists.index'), ['label' => $record?->doc_num ?? __('price_lists.create'), 'active' => true]],
        ]);
    }

    private function redirectAfterSave(Request $request, PriceList $record, bool $creating = false): RedirectResponse
    {
        $action = $this->submitAction($request);
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
            $creating ? __('price_lists.messages.created') : __('price_lists.messages.updated'),
        );
    }

    private function submitAction(Request $request): string
    {
        $action = $request->string('submit_action')->trim()->toString();

        return in_array($action, ['save', 'save_view', 'save_edit', 'save_back', 'save_new'], true) ? $action : 'save';
    }

    private function authorizeSubmitAction(Request $request, string $action): void
    {
        $permission = match ($action) {
            'save_view', 'save_back' => 'price_lists.view',
            'save_edit' => 'price_lists.edit',
            'save_new' => 'price_lists.create',
            default => null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403);
    }
}
