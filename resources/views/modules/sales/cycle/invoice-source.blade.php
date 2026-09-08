@extends('layouts.app')
@section('title', __('sales_ui.create_invoice'))
@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">{{ __('sales_ui.choose_invoice_source') }}</h5>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-invoices.index') }}" data-shortcut-action="form.back"><span class="fas fa-arrow-left me-1"></span>{{ __('Back') }}</a>
    </div>
    <div class="card-body">
        <p class="text-600 mb-4">{{ __('sales_ui.invoice_source_help') }}</p>
        <div class="row g-3">
            @can('sales_orders.view')
                @can('sales_orders.invoice')
                    <div class="col-lg-4">
                        <form method="GET" action="{{ route('admin.sales.sales-invoices.create') }}" class="border rounded h-100 p-3">
                            <h6><span class="fas fa-file-contract text-primary me-2"></span>{{ __('sales_ui.invoice_from_order') }}</h6>
                            <p class="small text-600">{{ __('sales_ui.invoice_from_order_help') }}</p>
                            <x-forms.label for="sales_order_doc_num" :label="__('Sales Order')" required />
                            <select class="form-select js-select2-ajax" id="sales_order_doc_num" name="sales_order_doc_num" data-url="{{ route('admin.sales.select2.invoiceable-orders') }}" data-placeholder="{{ __('Sales Order') }}" required></select>
                            <button class="btn btn-primary btn-sm mt-3" type="submit">{{ __('sales_ui.continue_invoice') }}</button>
                        </form>
                    </div>
                @endcan
            @endcan
            @can('sales_requests.view')
                <div class="col-lg-4">
                    <form method="GET" action="{{ route('admin.sales.sales-invoices.create') }}" class="border rounded h-100 p-3">
                        <h6><span class="fas fa-clipboard-list text-info me-2"></span>{{ __('sales_ui.invoice_from_request') }}</h6>
                        <p class="small text-600">{{ __('sales_ui.invoice_from_request_help') }}</p>
                        <x-forms.label for="source_request_doc_num" :label="__('sales_ui.source_sales_request')" required />
                        <select class="form-select js-select2-ajax" id="source_request_doc_num" name="source_request_doc_num" data-url="{{ route('admin.sales.select2.convertible-requests') }}" data-placeholder="{{ __('sales_ui.source_sales_request') }}" required></select>
                        <button class="btn btn-primary btn-sm mt-3" type="submit">{{ __('sales_ui.continue_invoice') }}</button>
                    </form>
                </div>
            @endcan
            <div class="col-lg-4">
                <div class="border rounded h-100 p-3 d-flex flex-column">
                    <h6><span class="fas fa-file-invoice-dollar text-success me-2"></span>{{ __('sales_ui.direct_invoice') }}</h6>
                    <p class="small text-600 flex-grow-1">{{ __('sales_ui.direct_invoice_help') }}</p>
                    <a class="btn btn-primary btn-sm align-self-start" href="{{ route('admin.sales.sales-invoices.create', ['direct' => 1]) }}">{{ __('sales_ui.create_direct_invoice') }}</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
