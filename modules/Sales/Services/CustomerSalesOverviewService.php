<?php

namespace Modules\Sales\Services;

use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;

class CustomerSalesOverviewService
{
    /** @return array<string, mixed> */
    public function forCustomer(Customer $customer, int $branchId): array
    {
        $invoices = CustomerInvoice::query()->where('company_id', $customer->company_id)->where('customer_id', $customer->id)->where('branch_id', $branchId)->where('document_type', CustomerInvoice::TypeInvoice);
        $orders = SalesOrder::query()->where('company_id', $customer->company_id)->where('customer_id', $customer->id)->where('branch_id', $branchId);

        return [
            'balances' => (clone $invoices)->with('currency')->where('posting_status', 'posted')->groupBy('currency_id')
                ->selectRaw('currency_id, sum(total_amount) as sales, sum(remaining_amount) as outstanding, sum(case when due_date < ? then remaining_amount else 0 end) as overdue, max(invoice_date) as last_sale', [now()->toDateString()])->get(),
            'openOrderCount' => (clone $orders)->whereIn('status', ['approved', 'partially_fulfilled'])->count(),
            'credits' => CustomerInvoice::query()->where('company_id', $customer->company_id)->where('customer_id', $customer->id)->where('branch_id', $branchId)->where('document_type', CustomerInvoice::TypeCreditNote)->where('posting_status', 'posted')->groupBy('currency_id')->selectRaw('currency_id, sum(total_amount) as returned, sum(credit_available_amount) as available')->get()->keyBy('currency_id'),
            'priceHistory' => CustomerInvoiceLine::query()->with('product', 'unit', 'invoice.currency')->whereHas('invoice', fn ($query) => $query->where('company_id', $customer->company_id)->where('customer_id', $customer->id)->where('branch_id', $branchId)->where('document_type', CustomerInvoice::TypeInvoice)->where('posting_status', 'posted'))->latest('id')->limit(30)->get(),
            'orders' => (clone $orders)->with('productionOrders', 'deliveries')->latest('id')->limit(10)->get(),
            'invoices' => (clone $invoices)->with('currency')->latest('id')->limit(10)->get(),
            'quotations' => Quotation::query()->where('company_id', $customer->company_id)->where('customer_id', $customer->id)->where('branch_id', $branchId)->latest('id')->limit(10)->get(),
            'receipts' => CustomerReceipt::query()->with('cheque', 'cashVoucher')->where('company_id', $customer->company_id)->where('customer_id', $customer->id)->where('branch_id', $branchId)->latest('id')->limit(10)->get(),
            'returns' => SalesReturn::query()->where('company_id', $customer->company_id)->where('customer_id', $customer->id)->where('branch_id', $branchId)->latest('id')->limit(10)->get(),
        ];
    }
}
