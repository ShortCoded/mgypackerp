<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Services\OperatingContextService;
use Modules\Sales\Exports\SalesCycleReportExport;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesCycleReportController extends Controller
{
    public function __construct(private readonly OperatingContextService $context) {}

    public function index(Request $request): View
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'], 422, 'Operating context is required.');
        $companyId = (int) $context['company_id'];
        $periodId = (int) $context['financial_period_id'];
        $from = $request->date('from');
        $to = $request->date('to');

        $openOrders = SalesOrder::query()->with('customer')->where('company_id', $companyId)->where('financial_period_id', $periodId)
            ->whereIn('status', [SalesOrder::StatusApproved, SalesOrder::StatusPartiallyFulfilled, SalesOrder::StatusHeldCredit])
            ->when($from, fn ($query) => $query->whereDate('order_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('order_date', '<=', $to))
            ->withSum('lines as ordered_quantity', 'quantity')->withSum('lines as delivered_quantity', 'delivered_quantity')
            ->withSum('lines as production_requested_quantity', 'production_requested_quantity')->orderBy('expected_delivery_date')->limit(100)->get();

        $orderHistory = SalesOrder::query()->with('customer')->where('company_id', $companyId)->where('financial_period_id', $periodId)
            ->when($from, fn ($query) => $query->whereDate('order_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('order_date', '<=', $to))
            ->withSum('lines as ordered_quantity', 'quantity')->withSum('lines as delivered_quantity', 'delivered_quantity')
            ->latest('order_date')->limit(100)->get();

        $salesByCustomer = CustomerInvoice::query()->join('customers', 'customers.id', '=', 'customer_invoices.customer_id')
            ->where('customer_invoices.company_id', $companyId)->where('customer_invoices.financial_period_id', $periodId)
            ->where('customer_invoices.document_type', CustomerInvoice::TypeInvoice)->where('customer_invoices.posting_status', 'posted')
            ->when($from, fn ($query) => $query->whereDate('invoice_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('invoice_date', '<=', $to))
            ->groupBy('customers.doc_num', 'customers.name')->selectRaw('customers.doc_num, customers.name, sum(customer_invoices.total_amount) as sales_value, sum(customer_invoices.remaining_amount) as outstanding')->orderByDesc('sales_value')->limit(100)->get();

        $salesByItem = DB::table('customer_invoice_lines')->join('customer_invoices', 'customer_invoices.id', '=', 'customer_invoice_lines.customer_invoice_id')->join('products', 'products.id', '=', 'customer_invoice_lines.product_id')
            ->where('customer_invoices.company_id', $companyId)->where('customer_invoices.financial_period_id', $periodId)
            ->where('customer_invoices.document_type', CustomerInvoice::TypeInvoice)->where('customer_invoices.posting_status', 'posted')
            ->when($from, fn ($query) => $query->whereDate('invoice_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('invoice_date', '<=', $to))
            ->groupBy('products.doc_num', 'products.name')->selectRaw('products.doc_num, products.name, sum(customer_invoice_lines.quantity) as sold_quantity, sum(customer_invoice_lines.line_total) as sales_value')->orderByDesc('sales_value')->limit(100)->get();

        $salesByCustomerItem = DB::table('customer_invoice_lines')->join('customer_invoices', 'customer_invoices.id', '=', 'customer_invoice_lines.customer_invoice_id')->join('customers', 'customers.id', '=', 'customer_invoices.customer_id')->join('products', 'products.id', '=', 'customer_invoice_lines.product_id')
            ->where('customer_invoices.company_id', $companyId)->where('customer_invoices.financial_period_id', $periodId)
            ->where('customer_invoices.document_type', CustomerInvoice::TypeInvoice)->where('customer_invoices.posting_status', 'posted')
            ->when($from, fn ($query) => $query->whereDate('invoice_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('invoice_date', '<=', $to))
            ->groupBy('customers.doc_num', 'customers.name', 'products.doc_num', 'products.name')
            ->selectRaw('customers.doc_num as customer_doc_num, customers.name as customer_name, products.doc_num as product_doc_num, products.name as product_name, sum(customer_invoice_lines.quantity) as sold_quantity, sum(customer_invoice_lines.line_total) as sales_value')
            ->orderByDesc('sales_value')->limit(100)->get();

        $salesByPeriod = CustomerInvoice::query()->where('company_id', $companyId)->where('financial_period_id', $periodId)
            ->where('document_type', CustomerInvoice::TypeInvoice)->where('posting_status', 'posted')
            ->when($from, fn ($query) => $query->whereDate('invoice_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('invoice_date', '<=', $to))
            ->groupBy('invoice_date')->selectRaw('invoice_date, count(*) as invoice_count, sum(total_amount) as sales_value')->orderBy('invoice_date')->get();

        $installments = DB::table('customer_invoice_payment_schedules')->join('customer_invoices', 'customer_invoices.id', '=', 'customer_invoice_payment_schedules.customer_invoice_id')->join('customers', 'customers.id', '=', 'customer_invoices.customer_id')
            ->where('customer_invoices.company_id', $companyId)->where('customer_invoices.financial_period_id', $periodId)->where('customer_invoices.posting_status', 'posted')
            ->whereRaw('customer_invoice_payment_schedules.amount > customer_invoice_payment_schedules.collected_amount + customer_invoice_payment_schedules.credited_amount')
            ->selectRaw('customer_invoices.doc_num, customers.name, customer_invoice_payment_schedules.due_date, customer_invoice_payment_schedules.amount - customer_invoice_payment_schedules.collected_amount - customer_invoice_payment_schedules.credited_amount as outstanding')
            ->orderBy('customer_invoice_payment_schedules.due_date')->get();
        $invoiceOutstanding = CustomerInvoice::query()->with('customer')->where('company_id', $companyId)->where('financial_period_id', $periodId)
            ->where('document_type', CustomerInvoice::TypeInvoice)->where('posting_status', 'posted')->where('remaining_amount', '>', 0)
            ->orderBy('due_date')->limit(100)->get();
        $upcomingCollections = $installments->filter(fn (object $row): bool => Carbon::parse($row->due_date)->isSameDay(today()) || Carbon::parse($row->due_date)->isFuture())->take(100);
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
            ->where('sales_returns.company_id', $companyId)->where('sales_returns.financial_period_id', $periodId)
            ->when($from, fn ($query) => $query->whereDate('return_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('return_date', '<=', $to))
            ->groupBy('sales_returns.reason_code')->selectRaw('sales_returns.reason_code, count(distinct sales_returns.id) as return_count, sum(sales_return_lines.quantity) as returned_quantity, sum(sales_return_lines.saleable_quantity) as saleable_quantity, sum(sales_return_lines.quarantine_quantity + sales_return_lines.rework_quantity + sales_return_lines.scrap_quantity) as rejected_quantity')->orderByDesc('return_count')->get();

        $returnAnalysis = DB::table('sales_return_lines')->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')->join('customers', 'customers.id', '=', 'sales_returns.customer_id')->leftJoin('products', 'products.id', '=', 'sales_return_lines.product_id')
            ->where('sales_returns.company_id', $companyId)->where('sales_returns.financial_period_id', $periodId)
            ->when($from, fn ($query) => $query->whereDate('return_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('return_date', '<=', $to))
            ->groupBy('customers.doc_num', 'customers.name', 'products.doc_num', 'products.name', 'sales_returns.reason_code', 'sales_return_lines.quality_disposition')
            ->selectRaw('customers.doc_num as customer_doc_num, customers.name as customer_name, products.doc_num as product_doc_num, products.name as product_name, sales_returns.reason_code, sales_return_lines.quality_disposition, sum(sales_return_lines.quantity) as returned_quantity')->orderByDesc('returned_quantity')->limit(100)->get();

        return view('modules.sales.cycle.report', compact('openOrders', 'orderHistory', 'salesByCustomer', 'salesByItem', 'salesByCustomerItem', 'salesByPeriod', 'invoiceOutstanding', 'installments', 'upcomingCollections', 'aging', 'returns', 'returnAnalysis', 'from', 'to'));
    }

    public function print(Request $request): View
    {
        return $this->index($request);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new SalesCycleReportExport($this->index($request)->getData()),
            'sales-cycle-report-'.now()->format('Ymd-His').'.xlsx',
        );
    }
}
