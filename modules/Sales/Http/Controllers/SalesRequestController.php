<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Sales\DataTables\SalesCycleDataTable;
use Modules\Sales\Http\Requests\SalesRequestWorkflowRequest;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\SalesRequestService;
use Modules\Sales\Services\SalesSelect2Service;

class SalesRequestController extends Controller
{
    public function __construct(private readonly OperatingContextService $context, private readonly SalesRequestService $service) {}

    public function index(Request $request): View|JsonResponse
    {
        $context = $this->context->snapshot($request);
        $records = SalesRequest::query()->with('customer')->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))->latest('request_date')->latest('id');
        if ($request->has('draw')) {
            return app(SalesCycleDataTable::class)->json($request, $records, 'sales_requests', 'request_date');
        }

        return view('modules.sales.cycle.index', ['kind' => 'sales_requests']);
    }

    public function create(Request $request): View
    {
        return $this->form($request);
    }

    public function edit(Request $request, SalesRequest $salesRequest): View
    {
        $this->assertBranch($request, $salesRequest);
        abort_unless(in_array($salesRequest->status, ['draft', 'rejected'], true), 422);

        return $this->form($request, $salesRequest);
    }

    public function show(Request $request, SalesRequest $salesRequest): View
    {
        $this->assertBranch($request, $salesRequest);

        return view('modules.sales.requests.show', ['record' => $salesRequest->load(['company', 'branch', 'branchStore', 'customer', 'currency', 'lines.product.color', 'lines.unit', 'quotations', 'orders']),
            'customers' => collect([$salesRequest->customer])->filter(),
            'currencies' => Currency::query()->forCompany($salesRequest->company_id)->active()->where('is_main', true)->get(),
            'stores' => collect([$salesRequest->branchStore])->filter()]);
    }

    public function store(SalesRequestWorkflowRequest $request): JsonResponse
    {
        return $this->saved($this->service->save($this->payload($request)));
    }

    public function update(SalesRequestWorkflowRequest $request, SalesRequest $salesRequest): JsonResponse
    {
        $this->assertBranch($request, $salesRequest);

        return $this->saved($this->service->save($this->payload($request), $salesRequest));
    }

    public function destroy(Request $request, SalesRequest $salesRequest): JsonResponse
    {
        $this->assertBranch($request, $salesRequest);
        $this->service->delete($salesRequest);

        return response()->json(['message' => __('Saved successfully.')]);
    }

    public function restore(Request $request, string $document): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $record = SalesRequest::onlyTrashed()->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])->where('doc_num', $document)->firstOrFail();
        $this->service->restore($record);

        return response()->json(['message' => __('Saved successfully.')]);
    }

    public function transition(SalesRequestWorkflowRequest $request, SalesRequest $salesRequest): JsonResponse
    {
        $this->assertBranch($request, $salesRequest);
        $status = $request->validated('status');
        $permission = match ($status) {
            'approved', 'rejected' => 'approve', 'cancelled', 'closed' => 'cancel', default => 'edit'
        };
        abort_unless($request->user()->can('sales_requests.'.$permission), 403);

        return $this->saved($this->service->transition($salesRequest, $status, $request->validated('reason')));
    }

    public function convert(SalesRequestWorkflowRequest $request, SalesRequest $salesRequest): JsonResponse
    {
        $this->assertBranch($request, $salesRequest);
        $target = $request->validated('target');
        abort_unless($request->user()->can($target === 'quotation' ? 'quotations.create' : 'sales_orders.create'), 403);
        $document = $this->service->convert($salesRequest, $target, $request->validated('lines'), $request->safe()->only(['customer_doc_num', 'currency_doc_num', 'branch_store_uuid', 'exchange_rate']));

        return response()->json(['data' => ['doc_num' => $document->doc_num, 'url' => route($target === 'quotation' ? 'admin.sales.quotations.show' : 'admin.sales.sales-orders.show', $document)]], 201);
    }

    public function print(Request $request, SalesRequest $salesRequest, ReportPdfService $pdf): Response
    {
        $this->assertBranch($request, $salesRequest);

        return $pdf->stream('reports.sales.request', ['record' => $salesRequest->load('company', 'customer', 'salesEmployee', 'branchStore', 'lines.product.color', 'lines.unit'),
            'title' => __('Sales Request').' — '.$salesRequest->doc_num, 'documentHeaderTitle' => __('Sales Request'),
            'printIdentityPolicy' => 'report', 'companyPrintIdentity' => $salesRequest->print_identity_snapshot ?: app(CompanyPrintIdentityService::class)->forCompany($salesRequest->company),
            'showPrices' => $request->user()->can('sales_orders.view_prices'), 'customerFacing' => true], 'sales-request-'.$salesRequest->doc_num.'.pdf');
    }

    private function form(Request $request, ?SalesRequest $record = null): View
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['branch_id'], 422);

        $record?->load('customer', 'currency', 'branchStore', 'salesEmployee', 'lines.product', 'lines.unit');

        return view('modules.sales.requests.form', ['record' => $record, ...app(SalesSelect2Service::class)->formOptions($request, $record)]);
    }

    /** @return array<string, mixed> */
    private function payload(SalesRequestWorkflowRequest $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['branch_id'], 422);
        $data = $request->validated();
        $values = collect($data)->except(['customer_doc_num', 'currency_doc_num', 'branch_store_uuid', 'sales_employee_doc_num', 'request_type', 'lines'])->all();
        $values['business_employee_id'] = app(SalesSelect2Service::class)->employeeId($context['company_id'], $context['branch_id'], $data['sales_employee_doc_num'] ?? null, $request->route('salesRequest')?->business_employee_id);
        $values['customer_id'] = ($data['request_type'] ?? null) === 'internal' || empty($data['customer_doc_num']) ? null : Customer::query()->forCompany($context['company_id'])->where('doc_num', $data['customer_doc_num'])->valueOrFail('id');
        $values['currency_id'] = empty($data['currency_doc_num']) ? null : Currency::query()->forCompany($context['company_id'])->where('doc_num', $data['currency_doc_num'])->valueOrFail('id');
        $values['branch_store_id'] = empty($data['branch_store_uuid']) ? null : BranchStore::query()->where('branch_id', $context['branch_id'])->where('public_uuid', $data['branch_store_uuid'])->valueOrFail('id');
        $values['lines'] = array_map(function (array $line) use ($context): array {
            return [...collect($line)->except(['product_doc_num', 'unit_doc_num'])->all(),
                'product_id' => Product::query()->forCompany($context['company_id'])->where('doc_num', $line['product_doc_num'])->valueOrFail('id'),
                'unit_id' => empty($line['unit_doc_num']) ? null : ItemUnit::query()->forCompany($context['company_id'])->where('doc_num', $line['unit_doc_num'])->valueOrFail('id')];
        }, $data['lines']);

        return [...$values, 'company_id' => $context['company_id'], 'branch_id' => $context['branch_id']];
    }

    private function saved(SalesRequest $record): JsonResponse
    {
        return response()->json([
            'message' => __('Saved successfully.'),
            'data' => ['doc_num' => $record->doc_num, 'url' => route('admin.sales.customer-requests.show', $record)],
        ], 200);
    }

    private function assertBranch(Request $request, SalesRequest $record): void
    {
        abort_unless((int) $record->branch_id === (int) $this->context->snapshot($request)['branch_id'], 404);
    }
}
