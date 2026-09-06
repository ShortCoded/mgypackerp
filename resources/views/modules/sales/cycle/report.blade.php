@extends('layouts.app')

@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))

@section('title', __('Sales Cycle Operational Report'))

@push('styles')
<style>
    @media print {
        .sales-cycle-print,
        .sales-cycle-print .row,
        .sales-cycle-print [class*="col-"] {
            box-sizing: border-box;
            max-width: 100%;
            min-width: 0;
        }

        .sales-cycle-print .row {
            margin-right: 0;
            margin-left: 0;
        }

        .sales-cycle-print .table-responsive {
            overflow: visible !important;
        }

        .sales-cycle-print table {
            width: 100% !important;
            table-layout: fixed;
            font-size: 7pt;
        }

        .sales-cycle-print th,
        .sales-cycle-print td {
            padding: 0.18rem;
            white-space: normal !important;
            overflow-wrap: anywhere;
        }

        .sales-cycle-print .report-filter-fields {
            display: none !important;
        }
    }
</style>
@endpush

@section('content')
<div class="sales-cycle-print" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <div class="page-print-actions d-flex justify-content-end gap-2 mb-3">
        @can('reports.sales.sales_orders.export')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.reports.sales.sales-orders.export', request()->query()) }}">{{ __('Excel') }}</a>@endcan
        @can('reports.sales.sales_orders.print')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.reports.sales.sales-orders.print', request()->query()) }}">{{ __('Print view') }}</a>@endcan
        @if(request()->routeIs('admin.reports.sales.sales-orders.print'))<button class="btn btn-primary btn-sm" type="button" onclick="window.print()">{{ __('Print') }}</button>@endif
    </div>
    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">{{ __('Sales Cycle Operational Report') }} · {{ $reportCurrency?->code }}</h5></div>
        <div class="card-body report-filter-fields">
            <form class="row g-3">
                <div class="col-md-3"><label class="form-label">{{ __('Currency') }}</label><select class="form-select" name="currency_doc_num">@foreach($currencies as $currency)<option value="{{ $currency->doc_num }}" @selected($filters['currency_doc_num'] === $currency->doc_num)>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">{{ __('Category') }}</label><input class="form-control" name="category_doc_num" value="{{ $filters['category_doc_num'] }}" placeholder="{{ __('Document number') }}"></div>
                <div class="col-md-3"><label class="form-label">{{ __('Warehouse') }}</label><select class="form-select" name="warehouse_uuid"><option value="">{{ __('All') }}</option>@foreach(\Modules\Core\Models\BranchStore::query()->where('branch_id', app(\Modules\Core\Services\OperatingContextService::class)->snapshot(request())['branch_id'])->get() as $warehouse)<option value="{{ $warehouse->public_uuid }}" @selected($filters['warehouse_uuid'] === $warehouse->public_uuid)>{{ $warehouse->name }}</option>@endforeach</select></div>

                <div class="col-md-3"><label class="form-label">{{ __('From') }}</label><input class="form-control" type="date" name="from" value="{{ request('from') }}"></div>
                <div class="col-md-3"><label class="form-label">{{ __('To') }}</label><input class="form-control" type="date" name="to" value="{{ request('to') }}"></div>
                <div class="col-md-3"><label class="form-label">{{ __('Customer') }}</label><input class="form-control" name="customer_doc_num" value="{{ $filters['customer_doc_num'] }}" placeholder="{{ __('Document number') }}"></div>
                <div class="col-md-3"><label class="form-label">{{ __('Product') }}</label><input class="form-control" name="product_doc_num" value="{{ $filters['product_doc_num'] }}" placeholder="{{ __('Document number') }}"></div>
                <div class="col-md-3"><label class="form-label">{{ __('Sales Representative') }}</label><input class="form-control" name="sales_person_doc_num" value="{{ $filters['sales_person_doc_num'] }}" placeholder="{{ __('Document number') }}"></div>
                <div class="col-md-3"><label class="form-label">{{ __('Branch') }}</label><input class="form-control" name="branch_doc_num" value="{{ $filters['branch_doc_num'] }}" placeholder="{{ __('Document number') }}"></div>
                <div class="col-md-3"><label class="form-label">{{ __('Quotation') }}</label><input class="form-control" name="quotation_doc_num" value="{{ $filters['quotation_doc_num'] }}" placeholder="{{ __('Document number') }}"></div>
                <div class="col-md-3"><label class="form-label">{{ __('Sales Order') }}</label><input class="form-control" name="order_doc_num" value="{{ $filters['order_doc_num'] }}" placeholder="{{ __('Document number') }}"></div>
                <div class="col-md-3"><label class="form-label">{{ __('Invoice') }}</label><input class="form-control" name="invoice_doc_num" value="{{ $filters['invoice_doc_num'] }}" placeholder="{{ __('Document number') }}"></div>
                <div class="col-md-3"><label class="form-label">{{ __('Quotation Status') }}</label><select class="form-select" name="quotation_status"><option value="">{{ __('All') }}</option>@foreach(\Modules\Sales\Models\Quotation::Statuses as $status)<option value="{{ $status }}" @selected($filters['quotation_status'] === $status)>{{ __(str($status)->replace('_', ' ')->title()->toString()) }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">{{ __('Order Status') }}</label><select class="form-select" name="order_status"><option value="">{{ __('All') }}</option>@foreach($orderStatuses as $status)<option value="{{ $status }}" @selected($filters['order_status'] === $status)>{{ __(str($status)->replace('_', ' ')->title()->toString()) }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">{{ __('Overdue State') }}</label><select class="form-select" name="overdue_state"><option value="">{{ __('All') }}</option><option value="overdue" @selected($filters['overdue_state'] === 'overdue')>{{ __('Overdue') }}</option><option value="not_overdue" @selected($filters['overdue_state'] === 'not_overdue')>{{ __('Not overdue') }}</option></select></div>
                <div class="col-md-3"><label class="form-label">{{ __('Payment State') }}</label><select class="form-select" name="payment_state"><option value="">{{ __('All') }}</option><option value="outstanding" @selected($filters['payment_state'] === 'outstanding')>{{ __('Outstanding') }}</option><option value="settled" @selected($filters['payment_state'] === 'settled')>{{ __('Settled') }}</option></select></div>
                <div class="col-md-3"><label class="form-label">{{ __('Return Reason') }}</label><select class="form-select" name="return_reason"><option value="">{{ __('All') }}</option>@foreach($returnReasons as $reason)<option value="{{ $reason }}" @selected($filters['return_reason'] === $reason)>{{ __(str($reason)->replace('_', ' ')->title()->toString()) }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">{{ __('Quality Disposition') }}</label><select class="form-select" name="quality_disposition"><option value="">{{ __('All') }}</option>@foreach(['saleable', 'quarantine', 'rework', 'scrap'] as $disposition)<option value="{{ $disposition }}" @selected($filters['quality_disposition'] === $disposition)>{{ __(str($disposition)->replace('_', ' ')->title()->toString()) }}</option>@endforeach</select></div>
                <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary w-100">{{ __('Apply') }}</button></div>
            </form>
        </div>
    </div>

    <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Quotation Status / History') }}</h6></div><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>{{ __('Quotation') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Date') }}</th><th>{{ __('Valid until') }}</th><th>{{ __('Status') }}</th><th>{{ __('Revision') }}</th><th class="text-end">{{ __('Total') }}</th></tr></thead><tbody>@foreach($quotations as $quotation)<tr><td><a href="{{ route('admin.sales.quotations.show', $quotation) }}">{{ $quotation->doc_num }}</a></td><td>{{ $quotation->customer?->doc_num }} / {{ $quotation->customer?->name }}</td><td>{{ $quotation->quotation_date?->toDateString() }}</td><td>{{ $quotation->valid_until?->toDateString() }}</td><td>{{ __(str($quotation->status)->replace('_', ' ')->title()->toString()) }}</td><td>{{ $quotation->currentRevision?->revision_code }}</td><td class="text-end">{{ $numbers->format($quotation->currentRevision?->total) }}</td></tr>@endforeach</tbody></table></div></div>

    <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Open / Partially Fulfilled / Overdue Orders') }}</h6></div><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>{{ __('Order') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Required date') }}</th><th>{{ __('Status') }}</th><th class="text-end">{{ __('Ordered') }}</th><th class="text-end">{{ __('Reserved') }}</th><th class="text-end">{{ __('Produced') }}</th><th class="text-end">{{ __('Delivered') }}</th><th class="text-end">{{ __('Production requested') }}</th></tr></thead><tbody>@foreach($openOrders as $order)<tr><td><a href="{{ route('admin.sales.sales-orders.show', $order) }}">{{ $order->doc_num }}</a></td><td>{{ $order->customer?->name }}</td><td>{{ $order->expected_delivery_date?->toDateString() }} @if($order->expected_delivery_date?->isBefore(today()))<span class="badge bg-danger">{{ __('Overdue') }}</span>@endif</td><td>{{ __(str($order->status)->replace('_', ' ')->title()->toString()) }}</td><td class="text-end">{{ $numbers->format($order->ordered_quantity) }}</td><td class="text-end">{{ $numbers->format($order->reserved_quantity) }}</td><td class="text-end">{{ $numbers->format($order->produced_quantity) }}</td><td class="text-end">{{ $numbers->format($order->delivered_quantity) }}</td><td class="text-end">{{ $numbers->format($order->production_requested_quantity) }}</td></tr>@endforeach</tbody></table></div></div>
    <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Customer Order History') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Order') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th><th class="text-end">{{ __('Ordered') }}</th><th class="text-end">{{ __('Delivered') }}</th></tr></thead><tbody>@foreach($orderHistory as $order)<tr><td><a href="{{ route('admin.sales.sales-orders.show', $order) }}">{{ $order->doc_num }}</a></td><td>{{ $order->customer?->doc_num }} / {{ $order->customer?->name }}</td><td>{{ $order->order_date?->toDateString() }}</td><td>{{ __(str($order->status)->replace('_', ' ')->title()->toString()) }}</td><td class="text-end">{{ $numbers->format($order->ordered_quantity) }}</td><td class="text-end">{{ $numbers->format($order->delivered_quantity) }}</td></tr>@endforeach</tbody></table></div></div>
    <div class="row g-3"><div class="col-xl-6"><div class="card h-100"><div class="card-header"><h6 class="mb-0">{{ __('Sales / Outstanding by Customer') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Customer') }}</th><th class="text-end">{{ __('Sales') }}</th><th class="text-end">{{ __('Outstanding') }}</th></tr></thead><tbody>@foreach($salesByCustomer as $row)<tr><td>{{ $row->doc_num }} / {{ $row->name }}</td><td class="text-end">{{ $numbers->format($row->sales_value) }}</td><td class="text-end">{{ $numbers->format($row->outstanding) }}</td></tr>@endforeach</tbody></table></div></div></div><div class="col-xl-6"><div class="card h-100"><div class="card-header"><h6 class="mb-0">{{ __('Due / Overdue Installments') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Due') }}</th><th class="text-end">{{ __('Outstanding') }}</th></tr></thead><tbody>@foreach($installments as $row)<tr><td>{{ $row->doc_num }}</td><td>{{ $row->name }}</td><td>{{ $row->due_date }}</td><td class="text-end">{{ $numbers->format($row->outstanding) }}</td></tr>@endforeach</tbody></table></div></div></div></div>
    <div class="card mt-3"><div class="card-header"><h6 class="mb-0">{{ __('Customer Aging') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Customer') }}</th><th class="text-end">{{ __('Current') }}</th><th class="text-end">1–30</th><th class="text-end">31–60</th><th class="text-end">61–90</th><th class="text-end">90+</th></tr></thead><tbody>@foreach($aging as $row)<tr><td>{{ $row['customer'] }}</td><td class="text-end">{{ $numbers->format($row['current']) }}</td><td class="text-end">{{ $numbers->format($row['1_30']) }}</td><td class="text-end">{{ $numbers->format($row['31_60']) }}</td><td class="text-end">{{ $numbers->format($row['61_90']) }}</td><td class="text-end">{{ $numbers->format($row['over_90']) }}</td></tr>@endforeach</tbody></table></div></div>
    <div class="row g-3 mt-0">
        <div class="col-xl-6"><div class="card h-100"><div class="card-header"><h6 class="mb-0">{{ __('Invoice Outstanding') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Due') }}</th><th class="text-end">{{ __('Outstanding') }}</th></tr></thead><tbody>@foreach($invoiceOutstanding as $invoice)<tr><td><a href="{{ route('admin.sales.customer-invoices.show', $invoice) }}">{{ $invoice->doc_num }}</a></td><td>{{ $invoice->customer?->name }}</td><td>{{ $invoice->due_date?->toDateString() }}</td><td class="text-end">{{ $numbers->format($invoice->remaining_amount) }}</td></tr>@endforeach</tbody></table></div></div></div>
        <div class="col-xl-6"><div class="card h-100"><div class="card-header"><h6 class="mb-0">{{ __('Upcoming Collections') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Due') }}</th><th class="text-end">{{ __('Outstanding') }}</th></tr></thead><tbody>@foreach($upcomingCollections as $row)<tr><td>{{ $row->doc_num }}</td><td>{{ $row->name }}</td><td>{{ $row->due_date }}</td><td class="text-end">{{ $numbers->format($row->outstanding) }}</td></tr>@endforeach</tbody></table></div></div></div>
    </div>
    <div class="row g-3 mt-0">
        <div class="col-xl-6"><div class="card h-100"><div class="card-header"><h6 class="mb-0">{{ __('Sales by Item') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Item') }}</th><th class="text-end">{{ __('Quantity') }}</th><th class="text-end">{{ __('Value') }}</th></tr></thead><tbody>@foreach($salesByItem as $row)<tr><td>{{ $row->doc_num }} / {{ $row->name }}</td><td class="text-end">{{ $numbers->format($row->sold_quantity) }}</td><td class="text-end">{{ $numbers->format($row->sales_value) }}</td></tr>@endforeach</tbody></table></div></div></div>
        <div class="col-xl-6"><div class="card h-100"><div class="card-header"><h6 class="mb-0">{{ __('Sales by Period') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Date') }}</th><th class="text-end">{{ __('Invoices') }}</th><th class="text-end">{{ __('Value') }}</th></tr></thead><tbody>@foreach($salesByPeriod as $row)<tr><td>{{ $row->invoice_date }}</td><td class="text-end">{{ $row->invoice_count }}</td><td class="text-end">{{ $numbers->format($row->sales_value) }}</td></tr>@endforeach</tbody></table></div></div></div>
    </div>
    <div class="card mt-3"><div class="card-header"><h6 class="mb-0">{{ __('Customer / Item Sales Analysis') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Item') }}</th><th class="text-end">{{ __('Quantity') }}</th><th class="text-end">{{ __('Value') }}</th></tr></thead><tbody>@foreach($salesByCustomerItem as $row)<tr><td>{{ $row->customer_doc_num }} / {{ $row->customer_name }}</td><td>{{ $row->product_doc_num }} / {{ $row->product_name }}</td><td class="text-end">{{ $numbers->format($row->sold_quantity) }}</td><td class="text-end">{{ $numbers->format($row->sales_value) }}</td></tr>@endforeach</tbody></table></div></div>
    <div class="card mt-3"><div class="card-header"><h6 class="mb-0">{{ __('Returns by Reason and Quality Disposition') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Reason') }}</th><th class="text-end">{{ __('Returns') }}</th><th class="text-end">{{ __('Quantity') }}</th><th class="text-end">{{ __('Saleable') }}</th><th class="text-end">{{ __('Rejected / Rework / Scrap') }}</th></tr></thead><tbody>@foreach($returns as $row)<tr><td>{{ __(str($row->reason_code)->replace('_', ' ')->title()->toString()) }}</td><td class="text-end">{{ $row->return_count }}</td><td class="text-end">{{ $numbers->format($row->returned_quantity) }}</td><td class="text-end">{{ $numbers->format($row->saleable_quantity) }}</td><td class="text-end">{{ $numbers->format($row->rejected_quantity) }}</td></tr>@endforeach</tbody></table></div></div>
    <div class="card mt-3"><div class="card-header"><h6 class="mb-0">{{ __('Customer / Item Return Analysis') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Item') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Disposition') }}</th><th class="text-end">{{ __('Quantity') }}</th></tr></thead><tbody>@foreach($returnAnalysis as $row)<tr><td>{{ $row->customer_doc_num }} / {{ $row->customer_name }}</td><td>{{ $row->product_doc_num }} / {{ $row->product_name }}</td><td>{{ __(str($row->reason_code)->replace('_', ' ')->title()->toString()) }}</td><td>{{ $row->quality_disposition ? __(str($row->quality_disposition)->replace('_', ' ')->title()->toString()) : '—' }}</td><td class="text-end">{{ $numbers->format($row->returned_quantity) }}</td></tr>@endforeach</tbody></table></div></div>
</div>
@include('modules.sales.cycle.partials.backorders')
@include('modules.sales.cycle.partials.sales-ledger')
@endsection
