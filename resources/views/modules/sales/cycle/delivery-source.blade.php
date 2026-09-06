@extends('layouts.app')

@section('title', __('sales_ui.create_delivery'))

@section('content')
<div class="card" data-sales-ui>
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div><h5 class="mb-0">{{ __('sales_ui.create_delivery') }}</h5><small class="text-600">{{ __('sales_ui.delivery_source_help') }}</small></div>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.delivery-notes.index') }}" data-shortcut-action="form.back">{{ __('Back') }}</a>
    </div>
    <div class="card-body">
        <form method="GET" action="{{ route('admin.sales.delivery-notes.create') }}" class="row g-3 align-items-end">
            <div class="col-md-9">
                <x-forms.label for="invoice_doc_num" :label="__('sales_ui.posted_invoice')" required />
                <select class="form-select js-select2-ajax" id="invoice_doc_num" name="invoice_doc_num" data-url="{{ route('admin.sales.select2.deliverable-invoices') }}" data-placeholder="{{ __('sales_ui.posted_invoice') }}" required></select>
            </div>
            <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">{{ __('sales_ui.continue_delivery') }}</button></div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
@include('modules.sales.cycle.partials.scripts')
@endpush
