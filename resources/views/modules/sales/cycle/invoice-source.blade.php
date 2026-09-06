@extends('layouts.app')
@section('title', __('sales_ui.create_invoice'))
@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0">{{ __('sales_ui.create_invoice') }}</h5><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-invoices.index') }}" data-shortcut-action="form.back">{{ __('Back') }}</a></div>
    <div class="card-body">
        <p>{{ __('sales_ui.invoice_source_help') }}</p>
        <form method="GET" action="{{ route('admin.sales.sales-invoices.create') }}" class="row g-3 align-items-end">
            <div class="col-md-8"><x-forms.label for="sales_order_doc_num" :label="__('Sales Order')" :required="true" /><select class="form-select js-select2-ajax" id="sales_order_doc_num" name="sales_order_doc_num" data-url="{{ route('admin.sales.select2.invoiceable-orders') }}" required><option value="">{{ __('Sales Order') }}</option></select></div>
            <div class="col-md-4"><button class="btn btn-primary" type="submit">{{ __('sales_ui.continue_invoice') }}</button></div>
        </form>
    </div>
</div>
@endsection
