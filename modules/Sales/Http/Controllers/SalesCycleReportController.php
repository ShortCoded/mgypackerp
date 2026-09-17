<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\ItemCategory;
use Modules\Core\Models\Product;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\HR\Models\HrArea;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrGovernorate;
use Modules\Sales\Exports\SalesCycleReportExport;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\SalesCycleReadService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesCycleReportController extends Controller
{
    private const REPORT_TYPES = [
        'financial', 'period', 'customers', 'products', 'invoices', 'receivables',
        'collections', 'returns', 'quotations', 'fulfillment', 'pricing', 'operational',
    ];

    public function __construct(private readonly OperatingContextService $context) {}

    public function index(Request $request): View
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'], 422, 'Operating context is required.');
        $companyId = (int) $context['company_id'];
        $periodId = (int) $context['financial_period_id'];
        $fullReport = $request->routeIs('*.print', '*.export') || $request->filled('operational_focus');
        $validated = $request->validate([
            'report' => ['nullable', 'string', Rule::in(self::REPORT_TYPES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $reportType = $validated['report'] ?? 'operational';
        $from = $request->date('from');
        $to = $request->date('to');
        $filters = collect([
            'currency_doc_num', 'warehouse_uuid', 'category_doc_num', 'customer_doc_num', 'product_doc_num', 'sales_person_doc_num', 'branch_doc_num',
            'country_doc_num', 'governorate_doc_num', 'city_doc_num', 'area_doc_num',
            'quotation_doc_num', 'order_doc_num', 'invoice_doc_num', 'quotation_status',
            'order_status', 'overdue_state', 'payment_state', 'return_reason', 'quality_disposition', 'operational_focus',
        ])->mapWithKeys(fn (string $field): array => [$field => $request->string($field)->trim()->toString()])->all();

        $reportCurrency = Currency::query()->where('company_id', $companyId)->where('status', 'active')
            ->when($filters['currency_doc_num'] !== '', fn (Builder $query) => $query->where('doc_num', $filters['currency_doc_num']), fn (Builder $query) => $query->where('is_main', true))
            ->orderByDesc('is_main')->first();
        $filters['currency_doc_num'] = $filters['currency_doc_num'] ?: ($reportCurrency?->doc_num ?? '');
        $currencies = collect([$reportCurrency])->filter();
        $currencyId = $reportCurrency?->id ?? -1;
        $categoryId = $this->contextId(ItemCategory::query()->where('company_id', $companyId), $filters['category_doc_num']);
        $warehouseId = $filters['warehouse_uuid'] === '' ? null : (BranchStore::query()->whereHas('branch', fn ($query) => $query->where('company_id', $companyId))->where('public_uuid', $filters['warehouse_uuid'])->value('id') ?? -1);
        $customerId = $this->contextId(Customer::query()->where('company_id', $companyId), $filters['customer_doc_num']);
        $countryId = $this->contextId(HrCountry::query(), $filters['country_doc_num']);
        $governorateId = $this->contextId(HrGovernorate::query(), $filters['governorate_doc_num']);
        $cityId = $this->contextId(HrCity::query(), $filters['city_doc_num']);
        $areaId = $this->contextId(HrArea::query(), $filters['area_doc_num']);
        $hasGeographyFilter = (bool) ($countryId || $governorateId || $cityId || $areaId);
        $geographyCustomerIds = $hasGeographyFilter
            ? Customer::query()->forCompany($companyId)
                ->when($countryId, fn (Builder $query) => $query->where('country_id', $countryId))
                ->when($governorateId, fn (Builder $query) => $query->where('governorate_id', $governorateId))
                ->when($cityId, fn (Builder $query) => $query->where('city_id', $cityId))
                ->when($areaId, fn (Builder $query) => $query->where('area_id', $areaId))
                ->pluck('id')
            : null;
        $productId = $this->contextId(Product::query()->where('company_id', $companyId), $filters['product_doc_num']);
        $salesPersonId = $this->contextId(HrEmployee::query()->where('company_id', $context['company_id']), $filters['sales_person_doc_num']);
        $branchId = $this->contextId(Branch::query()->where('company_id', $companyId), $filters['branch_doc_num']) ?? (int) $context['branch_id'];
        $quotationId = $this->contextId(Quotation::query()->where('company_id', $companyId), $filters['quotation_doc_num']);
        $orderId = $this->contextId(SalesOrder::query()->where('company_id', $companyId), $filters['order_doc_num']);
        $invoiceId = $this->contextId(CustomerInvoice::query()->where('company_id', $companyId), $filters['invoice_doc_num']);
        $quotationStatus = in_array($filters['quotation_status'], Quotation::Statuses, true) ? $filters['quotation_status'] : null;
        $orderStatuses = [
            SalesOrder::StatusDraft, SalesOrder::StatusPendingApproval, SalesOrder::StatusHeldCredit,
            SalesOrder::StatusApproved, SalesOrder::StatusPartiallyFulfilled, SalesOrder::StatusFulfilled,
            SalesOrder::StatusRejected, SalesOrder::StatusCancelled, SalesOrder::StatusClosed, SalesOrder::StatusReopened,
        ];
        $orderStatus = in_array($filters['order_status'], $orderStatuses, true) ? $filters['order_status'] : null;
        $overdueState = in_array($filters['overdue_state'], ['overdue', 'not_overdue'], true) ? $filters['overdue_state'] : null;
        $paymentState = in_array($filters['payment_state'], ['outstanding', 'settled'], true) ? $filters['payment_state'] : null;
        $returnReasons = [
            SalesReturn::ReasonExcess, SalesReturn::ReasonOrderEntry, SalesReturn::ReasonWrongItem,
            SalesReturn::ReasonWrongSpecification, SalesReturn::ReasonManufacturingDefect, SalesReturn::ReasonDamaged,
            SalesReturn::ReasonProductionDefect, SalesReturn::ReasonCustomerRejection, SalesReturn::ReasonOther,
        ];
        $returnReason = in_array($filters['return_reason'], $returnReasons, true) ? $filters['return_reason'] : null;
        $qualityDisposition = in_array($filters['quality_disposition'], ['saleable', 'quarantine', 'rework', 'scrap'], true) ? $filters['quality_disposition'] : null;

        $applyOrderFilters = static function ($query) use ($customerId, $geographyCustomerIds, $productId, $salesPersonId, $branchId, $quotationId, $orderId, $orderStatus, $overdueState, $currencyId, $warehouseId, $categoryId) {
            return $query->where('currency_id', $currencyId)
                ->when($warehouseId, fn ($builder) => $builder->where(function ($warehouseQuery) use ($warehouseId): void {
                    $warehouseQuery->where('branch_store_id', $warehouseId)
                        ->orWhereHas('deliveries', fn ($delivery) => $delivery
                            ->where('branch_store_id', $warehouseId)
                            ->where('status', 'posted'));
                }))
                ->when($categoryId, fn ($builder) => $builder->whereHas('lines.product', fn ($product) => $product->where('item_category_id', $categoryId)))
                ->when($customerId, fn ($builder) => $builder->where('customer_id', $customerId))
                ->when($geographyCustomerIds !== null, fn ($builder) => $builder->whereIn('customer_id', $geographyCustomerIds))
                ->when($productId, fn ($builder) => $builder->whereHas('lines', fn ($lines) => $lines->where('product_id', $productId)))
                ->when($salesPersonId, fn ($builder) => $builder->where('business_employee_id', $salesPersonId))
                ->when($branchId, fn ($builder) => $builder->where('branch_id', $branchId))
                ->when($quotationId, fn ($builder) => $builder->where('quotation_id', $quotationId))
                ->when($orderId, fn ($builder) => $builder->whereKey($orderId))
                ->when($orderStatus, fn ($builder) => $builder->where('status', $orderStatus))
                ->when($overdueState === 'overdue', fn ($builder) => $builder->whereDate('expected_delivery_date', '<', today()))
                ->when($overdueState === 'not_overdue', fn ($builder) => $builder->whereDate('expected_delivery_date', '>=', today()));
        };

        $hasOrderFilter = (bool) ($warehouseId || $categoryId || $productId || $salesPersonId || $quotationId || $orderId || $orderStatus || $overdueState);
        $filteredOrderIds = $hasOrderFilter
            ? $applyOrderFilters(SalesOrder::query()->where('company_id', $companyId)->where('financial_period_id', $periodId))->pluck('id')
            : collect();

        $applyInvoiceFilters = static function ($query) use ($customerId, $geographyCustomerIds, $productId, $branchId, $invoiceId, $paymentState, $hasOrderFilter, $filteredOrderIds, $currencyId) {
            return $query->where('customer_invoices.currency_id', $currencyId)
                ->when($customerId, fn ($builder) => $builder->where('customer_invoices.customer_id', $customerId))
                ->when($geographyCustomerIds !== null, fn ($builder) => $builder->whereIn('customer_invoices.customer_id', $geographyCustomerIds))
                ->when($branchId, fn ($builder) => $builder->where('customer_invoices.branch_id', $branchId))
                ->when($invoiceId, fn ($builder) => $builder->where('customer_invoices.id', $invoiceId))
                ->when($hasOrderFilter, fn ($builder) => $builder->whereIn('customer_invoices.sales_order_id', $filteredOrderIds))
                ->when($productId, fn ($builder) => $builder->whereExists(function ($lines) use ($productId): void {
                    $lines->selectRaw('1')->from('customer_invoice_lines as filtered_invoice_lines')
                        ->whereColumn('filtered_invoice_lines.customer_invoice_id', 'customer_invoices.id')
                        ->where('filtered_invoice_lines.product_id', $productId);
                }))
                ->when($paymentState === 'outstanding', fn ($builder) => $builder->where('customer_invoices.remaining_amount', '>', 0))
                ->when($paymentState === 'settled', fn ($builder) => $builder->where('customer_invoices.remaining_amount', '<=', 0));
        };

        $financialInvoiceQuery = $applyInvoiceFilters(CustomerInvoice::query())
            ->where('customer_invoices.company_id', $companyId)
            ->where('customer_invoices.financial_period_id', $periodId)
            ->where('customer_invoices.document_type', CustomerInvoice::TypeInvoice)
            ->where('customer_invoices.posting_status', CustomerInvoice::StatusPosted)
            ->when($from, fn ($query) => $query->whereDate('customer_invoices.invoice_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('customer_invoices.invoice_date', '<=', $to));
        $financialCreditQuery = $applyInvoiceFilters(CustomerInvoice::query())
            ->where('customer_invoices.company_id', $companyId)
            ->where('customer_invoices.financial_period_id', $periodId)
            ->where('customer_invoices.document_type', CustomerInvoice::TypeCreditNote)
            ->where('customer_invoices.posting_status', CustomerInvoice::StatusPosted)
            ->when($from, fn ($query) => $query->whereDate('customer_invoices.invoice_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('customer_invoices.invoice_date', '<=', $to));
        $invoiceTotals = (clone $financialInvoiceQuery)->selectRaw(
            'count(*) as invoice_count, coalesce(sum(total_amount), 0) as gross_sales, coalesce(sum(paid_amount), 0) as collections, coalesce(sum(remaining_amount), 0) as outstanding'
        )->first();
        $creditNotesTotal = (float) (clone $financialCreditQuery)->sum('total_amount');
        $grossSales = (float) ($invoiceTotals?->gross_sales ?? 0);
        $collections = (float) ($invoiceTotals?->collections ?? 0);
        $netSales = $grossSales - $creditNotesTotal;
        $financialSummary = [
            'invoice_count' => (int) ($invoiceTotals?->invoice_count ?? 0),
            'gross_sales' => $grossSales,
            'credit_notes' => $creditNotesTotal,
            'net_sales' => $netSales,
            'collections' => $collections,
            'outstanding' => (float) ($invoiceTotals?->outstanding ?? 0),
            'overdue_outstanding' => (float) (clone $financialInvoiceQuery)
                ->where('customer_invoices.remaining_amount', '>', 0)
                ->whereDate('customer_invoices.due_date', '<', today())
                ->sum('customer_invoices.remaining_amount'),
            'collection_rate' => $netSales > 0 ? round(($collections / $netSales) * 100, 2) : 0,
            'return_rate' => $grossSales > 0 ? round(($creditNotesTotal / $grossSales) * 100, 2) : 0,
        ];

        $quotations = Quotation::query()->with(['customer', 'currentRevision'])
            ->where('company_id', $companyId)->where('currency_id', $currencyId)
            ->when($customerId, fn ($query) => $query->where('customer_id', $customerId))
            ->when($geographyCustomerIds !== null, fn ($query) => $query->whereIn('customer_id', $geographyCustomerIds))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->when($salesPersonId, fn ($query) => $query->where('business_employee_id', $salesPersonId))
            ->when($quotationId, fn ($query) => $query->whereKey($quotationId))
            ->when($quotationStatus, fn ($query) => $query->where('status', $quotationStatus))
            ->when($filters['operational_focus'] === 'pending_sales_actions', fn ($query) => $query->operationallyPending())
            ->when($productId, fn ($query) => $query->whereHas('revisions.lines', fn ($lines) => $lines->where('product_id', $productId)))
            ->when($from, fn ($query) => $query->whereDate('quotation_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('quotation_date', '<=', $to))
            ->latest('quotation_date')->when(! $fullReport, fn ($query) => $query->limit(100))->get();

        $openOrdersQuery = $applyOrderFilters(SalesOrder::query()->with(['customer', 'lines'])->where('company_id', $companyId)->where('financial_period_id', $periodId));
        $filters['operational_focus'] === 'pending_sales_actions'
            ? $openOrdersQuery->operationallyOpen()
            : $openOrdersQuery->whereIn('status', [SalesOrder::StatusApproved, SalesOrder::StatusPartiallyFulfilled, SalesOrder::StatusHeldCredit]);
        $openOrders = $openOrdersQuery
            ->when($from, fn ($query) => $query->whereDate('order_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('order_date', '<=', $to))
            ->withSum('lines as ordered_quantity', 'quantity')->withSum('lines as delivered_quantity', 'delivered_quantity')
            ->withSum('lines as reserved_quantity', 'reserved_quantity')->withSum('lines as produced_quantity', 'produced_quantity')
            ->withSum('lines as production_requested_quantity', 'production_requested_quantity')->orderBy('expected_delivery_date')->when(! $fullReport, fn ($query) => $query->limit(100))->get();

        $salesRequests = SalesRequest::query()
            ->where('company_id', $companyId)
            ->where('financial_period_id', $periodId)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->when($customerId, fn ($query) => $query->where('customer_id', $customerId))
            ->when($salesPersonId, fn ($query) => $query->where('business_employee_id', $salesPersonId))
            ->when($productId, fn ($query) => $query->whereHas('lines', fn ($lineQuery) => $lineQuery->where('product_id', $productId)))
            ->when($from, fn ($query) => $query->whereDate('request_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('request_date', '<=', $to))
            ->operationallyOpen()
            ->with(['branch', 'customer', 'lines.product', 'lines.unit'])
            ->latest('request_date')
            ->when(! $fullReport, fn ($query) => $query->limit(100))
            ->get()
            ->each(function (SalesRequest $salesRequest): void {
                $remaining = $salesRequest->lines->reduce(
                    fn (string $total, $line): string => bcadd($total, bcsub((string) $line->quantity, (string) $line->converted_quantity, 8), 8),
                    '0.00000000',
                );
                $salesRequest->setAttribute('remaining_quantity', $remaining);
            });
        $salesActionCount = $salesRequests->count() + $quotations->count() + $openOrders->count();

        $salesByCustomer = $applyInvoiceFilters(CustomerInvoice::query()->join('customers', 'customers.id', '=', 'customer_invoices.customer_id'))
            ->where('customer_invoices.company_id', $companyId)->where('customer_invoices.financial_period_id', $periodId)
            ->where('customer_invoices.document_type', CustomerInvoice::TypeInvoice)->where('customer_invoices.posting_status', 'posted')
            ->when($from, fn ($query) => $query->whereDate('invoice_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('invoice_date', '<=', $to))
            ->groupBy('customers.doc_num', 'customers.name')->selectRaw('customers.doc_num, customers.name, sum(customer_invoices.total_amount) as sales_value, sum(customer_invoices.remaining_amount) as outstanding')->orderByDesc('sales_value')->when(! $fullReport, fn ($query) => $query->limit(100))->get();

        $salesByItem = $applyInvoiceFilters(DB::table('customer_invoice_lines')->join('customer_invoices', 'customer_invoices.id', '=', 'customer_invoice_lines.customer_invoice_id')->join('products', 'products.id', '=', 'customer_invoice_lines.product_id'))
            ->where('customer_invoices.company_id', $companyId)->where('customer_invoices.financial_period_id', $periodId)
            ->where('customer_invoices.document_type', CustomerInvoice::TypeInvoice)->where('customer_invoices.posting_status', 'posted')->when($productId, fn ($query) => $query->where('customer_invoice_lines.product_id', $productId))
            ->when($from, fn ($query) => $query->whereDate('invoice_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('invoice_date', '<=', $to))
            ->groupBy('products.doc_num', 'products.name')->selectRaw('products.doc_num, products.name, sum(customer_invoice_lines.quantity) as sold_quantity, sum(customer_invoice_lines.line_total) as sales_value')->orderByDesc('sales_value')->when(! $fullReport, fn ($query) => $query->limit(100))->get();

        $salesByCustomerItem = $applyInvoiceFilters(DB::table('customer_invoice_lines')->join('customer_invoices', 'customer_invoices.id', '=', 'customer_invoice_lines.customer_invoice_id')->join('customers', 'customers.id', '=', 'customer_invoices.customer_id')->join('products', 'products.id', '=', 'customer_invoice_lines.product_id'))
            ->where('customer_invoices.company_id', $companyId)->where('customer_invoices.financial_period_id', $periodId)
            ->where('customer_invoices.document_type', CustomerInvoice::TypeInvoice)->where('customer_invoices.posting_status', 'posted')->when($productId, fn ($query) => $query->where('customer_invoice_lines.product_id', $productId))
            ->when($from, fn ($query) => $query->whereDate('invoice_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('invoice_date', '<=', $to))
            ->groupBy('customers.doc_num', 'customers.name', 'products.doc_num', 'products.name')
            ->selectRaw('customers.doc_num as customer_doc_num, customers.name as customer_name, products.doc_num as product_doc_num, products.name as product_name, sum(customer_invoice_lines.quantity) as sold_quantity, sum(customer_invoice_lines.line_total) as sales_value')
            ->orderByDesc('sales_value')->when(! $fullReport, fn ($query) => $query->limit(100))->get();

        $salesByPeriod = $applyInvoiceFilters(CustomerInvoice::query())->where('company_id', $companyId)->where('financial_period_id', $periodId)
            ->where('document_type', CustomerInvoice::TypeInvoice)->where('posting_status', 'posted')
            ->when($from, fn ($query) => $query->whereDate('invoice_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('invoice_date', '<=', $to))
            ->groupBy('invoice_date')->selectRaw('invoice_date, count(*) as invoice_count, sum(total_amount) as sales_value')->orderBy('invoice_date')->get();

        $installments = $applyInvoiceFilters(DB::table('customer_invoice_payment_schedules')->join('customer_invoices', 'customer_invoices.id', '=', 'customer_invoice_payment_schedules.customer_invoice_id')->join('customers', 'customers.id', '=', 'customer_invoices.customer_id'))
            ->where('customer_invoices.company_id', $companyId)->where('customer_invoices.financial_period_id', $periodId)->where('customer_invoices.posting_status', 'posted')
            ->whereRaw('customer_invoice_payment_schedules.amount > customer_invoice_payment_schedules.collected_amount + customer_invoice_payment_schedules.credited_amount')
            ->selectRaw('customer_invoices.doc_num, customers.name, customer_invoice_payment_schedules.due_date, customer_invoice_payment_schedules.amount - customer_invoice_payment_schedules.collected_amount - customer_invoice_payment_schedules.credited_amount as outstanding')
            ->orderBy('customer_invoice_payment_schedules.due_date')->get();
        $invoiceOutstanding = $applyInvoiceFilters(CustomerInvoice::query()->with('customer'))->where('company_id', $companyId)->where('financial_period_id', $periodId)
            ->where('document_type', CustomerInvoice::TypeInvoice)->where('posting_status', 'posted')->where('remaining_amount', '>', 0)
            ->orderBy('due_date')->when(! $fullReport, fn ($query) => $query->limit(100))->get();
        $upcomingCollections = $installments->filter(fn (object $row): bool => Carbon::parse($row->due_date)->isSameDay(today()) || Carbon::parse($row->due_date)->isFuture())->take(100);
        $customerReceipts = CustomerReceipt::query()
            ->with(['customer', 'receivedByEmployee', 'currency', 'cashVoucher', 'cheque', 'bankAccount', 'allocations.invoice'])
            ->where('company_id', $companyId)
            ->where('financial_period_id', $periodId)
            ->where('branch_id', $branchId)
            ->where('currency_id', $currencyId)
            ->when($customerId, fn (Builder $query) => $query->where('customer_id', $customerId))
            ->when($geographyCustomerIds !== null, fn (Builder $query) => $query->whereIn('customer_id', $geographyCustomerIds))
            ->when($salesPersonId, fn (Builder $query) => $query->whereHas('order', fn (Builder $order) => $order->where('business_employee_id', $salesPersonId)))
            ->when($orderId, fn (Builder $query) => $query->where(fn (Builder $source) => $source
                ->where('sales_order_id', $orderId)
                ->orWhereHas('allocations.invoice', fn (Builder $invoice) => $invoice->where('sales_order_id', $orderId))))
            ->when($invoiceId, fn (Builder $query) => $query->whereHas('allocations', fn (Builder $allocation) => $allocation->where('customer_invoice_id', $invoiceId)))
            ->when($from, fn (Builder $query) => $query->whereDate('receipt_date', '>=', $from))
            ->when($to, fn (Builder $query) => $query->whereDate('receipt_date', '<=', $to))
            ->latest('receipt_date')
            ->latest('id')
            ->when(! $fullReport, fn (Builder $query) => $query->limit(100))
            ->get();
        $aging = $installments->groupBy('name')->map(function ($rows, string $customer): array {
            $buckets = ['current' => '0', '1_30' => '0', '31_60' => '0', '61_90' => '0', 'over_90' => '0'];
            foreach ($rows as $row) {
                $days = Carbon::parse($row->due_date)->diffInDays(today(), false);
                $bucket = $days <= 0 ? 'current' : ($days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : 'over_90')));
                $buckets[$bucket] = bcadd($buckets[$bucket], (string) $row->outstanding, 4);
            }

            return ['customer' => $customer, ...$buckets];
        })->values();

        $returns = SalesReturn::query()->join('sales_return_lines', 'sales_return_lines.sales_return_id', '=', 'sales_returns.id')->join('customers', 'customers.id', '=', 'sales_returns.customer_id')
            ->where('sales_returns.company_id', $companyId)->where('sales_returns.financial_period_id', $periodId)->where(function ($query) use ($currencyId): void {
                $query->whereExists(fn ($invoice) => $invoice->selectRaw('1')->from('customer_invoices')->whereColumn('customer_invoices.id', 'sales_returns.customer_invoice_id')->where('customer_invoices.currency_id', $currencyId))
                    ->orWhereExists(fn ($order) => $order->selectRaw('1')->from('sales_orders')->whereColumn('sales_orders.id', 'sales_returns.sales_order_id')->where('sales_orders.currency_id', $currencyId));
            })
            ->when($customerId, fn ($query) => $query->where('sales_returns.customer_id', $customerId))
            ->when($geographyCustomerIds !== null, fn ($query) => $query->whereIn('sales_returns.customer_id', $geographyCustomerIds))
            ->when($productId, fn ($query) => $query->where('sales_return_lines.product_id', $productId))
            ->when($branchId, fn ($query) => $query->where('sales_returns.branch_id', $branchId))
            ->when($orderId, fn ($query) => $query->where('sales_returns.sales_order_id', $orderId))
            ->when($invoiceId, fn ($query) => $query->where('sales_returns.customer_invoice_id', $invoiceId))
            ->when($returnReason, fn ($query) => $query->where('sales_returns.reason_code', $returnReason))
            ->when($qualityDisposition, fn ($query) => $query->where('sales_return_lines.quality_disposition', 'like', "%{$qualityDisposition}%"))
            ->when($from, fn ($query) => $query->whereDate('return_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('return_date', '<=', $to))
            ->groupBy('sales_returns.reason_code')->selectRaw('sales_returns.reason_code, count(distinct sales_returns.id) as return_count, sum(sales_return_lines.quantity) as returned_quantity, sum(sales_return_lines.saleable_quantity) as saleable_quantity, sum(sales_return_lines.quarantine_quantity + sales_return_lines.rework_quantity + sales_return_lines.scrap_quantity) as rejected_quantity')->orderByDesc('return_count')->get();

        $returnAnalysis = DB::table('sales_return_lines')->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')->join('customers', 'customers.id', '=', 'sales_returns.customer_id')->leftJoin('products', 'products.id', '=', 'sales_return_lines.product_id')
            ->where('sales_returns.company_id', $companyId)->where('sales_returns.financial_period_id', $periodId)->where(function ($query) use ($currencyId): void {
                $query->whereExists(fn ($invoice) => $invoice->selectRaw('1')->from('customer_invoices')->whereColumn('customer_invoices.id', 'sales_returns.customer_invoice_id')->where('customer_invoices.currency_id', $currencyId))
                    ->orWhereExists(fn ($order) => $order->selectRaw('1')->from('sales_orders')->whereColumn('sales_orders.id', 'sales_returns.sales_order_id')->where('sales_orders.currency_id', $currencyId));
            })
            ->when($customerId, fn ($query) => $query->where('sales_returns.customer_id', $customerId))
            ->when($geographyCustomerIds !== null, fn ($query) => $query->whereIn('sales_returns.customer_id', $geographyCustomerIds))
            ->when($productId, fn ($query) => $query->where('sales_return_lines.product_id', $productId))
            ->when($branchId, fn ($query) => $query->where('sales_returns.branch_id', $branchId))
            ->when($orderId, fn ($query) => $query->where('sales_returns.sales_order_id', $orderId))
            ->when($invoiceId, fn ($query) => $query->where('sales_returns.customer_invoice_id', $invoiceId))
            ->when($returnReason, fn ($query) => $query->where('sales_returns.reason_code', $returnReason))
            ->when($qualityDisposition, fn ($query) => $query->where('sales_return_lines.quality_disposition', 'like', "%{$qualityDisposition}%"))
            ->when($from, fn ($query) => $query->whereDate('return_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('return_date', '<=', $to))
            ->groupBy('customers.doc_num', 'customers.name', 'products.doc_num', 'products.name', 'sales_returns.reason_code', 'sales_return_lines.quality_disposition')
            ->selectRaw('customers.doc_num as customer_doc_num, customers.name as customer_name, products.doc_num as product_doc_num, products.name as product_name, sales_returns.reason_code, sales_return_lines.quality_disposition, sum(sales_return_lines.quantity) as returned_quantity')->orderByDesc('returned_quantity')->when(! $fullReport, fn ($query) => $query->limit(100))->get();

        $readService = app(SalesCycleReadService::class);
        $readFilters = ['customer_id' => $customerId, 'product_id' => $productId, 'category_id' => $categoryId, 'currency_id' => $currencyId,
            'customer_ids' => $geographyCustomerIds,
            'branch_store_id' => $warehouseId, 'sales_person_id' => $salesPersonId, 'order_id' => $orderId, 'invoice_id' => $invoiceId,
            'overdue_state' => $overdueState, 'payment_state' => $paymentState, 'from' => $from, 'to' => $to];
        $ledgerQuery = $readService->ledger($companyId, $branchId, $readFilters)->withSum(['creditNotes as returns_amount' => fn ($query) => $query->where('posting_status', 'posted')], 'total_amount');
        $salesLedger = $fullReport ? $ledgerQuery->get() : $ledgerQuery->paginate(25, ['*'], 'ledger_page')->withQueryString();

        $pricingDate = ($to ?? today())->toDateString();
        $unpricedProducts = collect();
        $customersWithoutPriceLists = collect();
        $customerProductPricingGaps = collect();
        if ($reportType === 'pricing') {
            $unpricedProducts = DB::table('products as pricing_products')
                ->leftJoin('item_categories', 'item_categories.id', '=', 'pricing_products.item_category_id')
                ->where('pricing_products.company_id', $companyId)->where('pricing_products.status', 'active')->whereNull('pricing_products.deleted_at')
                ->whereIn('pricing_products.item_classification', Product::salesItemClassifications())
                ->when($productId, fn ($query) => $query->where('pricing_products.id', $productId))
                ->when($categoryId, fn ($query) => $query->where('pricing_products.item_category_id', $categoryId))
                ->whereNotExists(fn ($query) => $this->effectivePriceExists($query, 'pricing_products.id', $companyId, $currencyId, $pricingDate, null))
                ->select('pricing_products.doc_num', 'pricing_products.name', 'item_categories.name as category_name')
                ->orderBy('pricing_products.name')->when(! $fullReport, fn ($query) => $query->limit(200))->get();

            $customersWithoutPriceLists = DB::table('customers as pricing_customers')
                ->where('pricing_customers.company_id', $companyId)->where('pricing_customers.status', 'active')->whereNull('pricing_customers.deleted_at')
                ->when($customerId, fn ($query) => $query->where('pricing_customers.id', $customerId))
                ->when($geographyCustomerIds !== null, fn ($query) => $query->whereIn('pricing_customers.id', $geographyCustomerIds))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('price_lists as customer_price_lists')
                    ->whereColumn('customer_price_lists.customer_id', 'pricing_customers.id')
                    ->where('customer_price_lists.company_id', $companyId)->where('customer_price_lists.currency_id', $currencyId)
                    ->whereNull('customer_price_lists.deleted_at')->whereDate('customer_price_lists.valid_from', '<=', $pricingDate)
                    ->where(fn ($dates) => $dates->whereNull('customer_price_lists.valid_until')->orWhereDate('customer_price_lists.valid_until', '>=', $pricingDate)))
                ->select('pricing_customers.doc_num', 'pricing_customers.name')->orderBy('pricing_customers.name')
                ->when(! $fullReport, fn ($query) => $query->limit(200))->get();

            $customerProductPricingGaps = DB::table('customers as coverage_customers')->crossJoin('products as coverage_products')
                ->where('coverage_customers.company_id', $companyId)->where('coverage_customers.status', 'active')->whereNull('coverage_customers.deleted_at')
                ->whereColumn('coverage_products.company_id', 'coverage_customers.company_id')->where('coverage_products.status', 'active')->whereNull('coverage_products.deleted_at')
                ->whereIn('coverage_products.item_classification', Product::salesItemClassifications())
                ->when($customerId, fn ($query) => $query->where('coverage_customers.id', $customerId))
                ->when($geographyCustomerIds !== null, fn ($query) => $query->whereIn('coverage_customers.id', $geographyCustomerIds))
                ->when($productId, fn ($query) => $query->where('coverage_products.id', $productId))
                ->when($categoryId, fn ($query) => $query->where('coverage_products.item_category_id', $categoryId))
                ->whereNotExists(function ($query) use ($companyId, $currencyId, $pricingDate): void {
                    $query->selectRaw('1')->from('price_list_lines as coverage_lines')->join('price_lists as coverage_lists', 'coverage_lists.id', '=', 'coverage_lines.price_list_id')
                        ->whereColumn('coverage_lines.product_id', 'coverage_products.id')->where('coverage_lists.company_id', $companyId)
                        ->where('coverage_lists.currency_id', $currencyId)->whereNull('coverage_lists.deleted_at')
                        ->where(fn ($scope) => $scope->whereColumn('coverage_lists.customer_id', 'coverage_customers.id')->orWhereNull('coverage_lists.customer_id'))
                        ->whereDate('coverage_lists.valid_from', '<=', $pricingDate)
                        ->where(fn ($dates) => $dates->whereNull('coverage_lists.valid_until')->orWhereDate('coverage_lists.valid_until', '>=', $pricingDate));
                })
                ->select('coverage_customers.doc_num as customer_doc_num', 'coverage_customers.name as customer_name', 'coverage_products.doc_num as product_doc_num', 'coverage_products.name as product_name')
                ->orderBy('coverage_customers.name')->orderBy('coverage_products.name')->when(! $fullReport, fn ($query) => $query->limit(500))->get();
        }

        $filterOptions = [
            'customer' => $customerId && $customerId > 0 ? Customer::withTrashed()->where('company_id', $companyId)->find($customerId) : null,
            'product' => $productId && $productId > 0 ? Product::withTrashed()->where('company_id', $companyId)->find($productId) : null,
            'employee' => $salesPersonId && $salesPersonId > 0 ? HrEmployee::withTrashed()->where('company_id', $companyId)->find($salesPersonId) : null,
            'warehouse' => $warehouseId && $warehouseId > 0 ? BranchStore::query()->whereHas('branch', fn ($query) => $query->where('company_id', $companyId))->find($warehouseId) : null,
            'quotation' => $quotationId && $quotationId > 0 ? Quotation::query()->where('company_id', $companyId)->find($quotationId) : null,
            'order' => $orderId && $orderId > 0 ? SalesOrder::query()->where('company_id', $companyId)->find($orderId) : null,
            'invoice' => $invoiceId && $invoiceId > 0 ? CustomerInvoice::query()->where('company_id', $companyId)->find($invoiceId) : null,
            'country' => $countryId && $countryId > 0 ? HrCountry::query()->find($countryId) : null,
            'governorate' => $governorateId && $governorateId > 0 ? HrGovernorate::query()->find($governorateId) : null,
            'city' => $cityId && $cityId > 0 ? HrCity::query()->find($cityId) : null,
            'area' => $areaId && $areaId > 0 ? HrArea::query()->find($areaId) : null,
        ];

        return view('modules.sales.cycle.report', compact('reportType', 'currencies', 'reportCurrency', 'financialSummary', 'filterOptions', 'salesLedger', 'salesRequests', 'salesActionCount', 'quotations', 'openOrders', 'salesByCustomer', 'salesByItem', 'salesByCustomerItem', 'salesByPeriod', 'invoiceOutstanding', 'installments', 'upcomingCollections', 'customerReceipts', 'aging', 'returns', 'returnAnalysis', 'unpricedProducts', 'customersWithoutPriceLists', 'customerProductPricingGaps', 'pricingDate', 'from', 'to', 'filters', 'orderStatuses', 'returnReasons'));
    }

    public function print(Request $request, ReportPdfService $pdf, CompanyPrintIdentityService $printIdentity): Response
    {
        $data = $this->index($request)->getData();
        $context = $this->context->snapshot($request);
        $company = Company::query()->findOrFail($context['company_id']);

        $reportType = $data['reportType'] ?? 'operational';
        $reportTitle = __('sales_ui.reports.types.'.$reportType);

        return $pdf->stream('reports.sales.cycle', [
            ...$data,
            'title' => $reportTitle,
            'companyPrintIdentity' => $printIdentity->forCompany($company),
        ], 'sales-'.$reportType.'-report.pdf');
    }

    public function export(Request $request, string $format = 'xlsx'): BinaryFileResponse
    {
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);
        $report = $this->index($request)->getData();
        $reportType = $report['reportType'] ?? 'operational';

        return Excel::download(
            new SalesCycleReportExport($report),
            'sales-'.$reportType.'-report-'.now()->format('Ymd-His').'.'.$format,
            $format === 'csv' ? ExcelWriter::CSV : ExcelWriter::XLSX,
        );
    }

    private function contextId(Builder $query, string $docNum): ?int
    {
        if ($docNum === '') {
            return null;
        }

        $id = $query->where('doc_num', $docNum)->value('id');

        return $id === null ? -1 : (int) $id;
    }

    private function effectivePriceExists(mixed $query, string $productColumn, int $companyId, int $currencyId, string $date, ?int $customerId): void
    {
        $query->selectRaw('1')->from('price_list_lines as effective_lines')->join('price_lists as effective_lists', 'effective_lists.id', '=', 'effective_lines.price_list_id')
            ->whereColumn('effective_lines.product_id', $productColumn)->where('effective_lists.company_id', $companyId)
            ->where('effective_lists.currency_id', $currencyId)->where('effective_lists.customer_id', $customerId)
            ->whereNull('effective_lists.deleted_at')->whereDate('effective_lists.valid_from', '<=', $date)
            ->where(fn ($dates) => $dates->whereNull('effective_lists.valid_until')->orWhereDate('effective_lists.valid_until', '>=', $date));
    }
}
