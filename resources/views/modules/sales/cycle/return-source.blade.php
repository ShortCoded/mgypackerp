@extends('layouts.app')
@section('title', __('sales_ui.create_return'))
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0">{{ __('sales_ui.create_return') }}</h5>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-returns.index') }}" data-shortcut-action="form.back" title="{{ __('common.shortcuts.back') }}" data-bs-title="{{ __('common.shortcuts.back') }}"><span class="fas fa-arrow-left me-1"></span>{{ __('Back') }}</a>
    </div>
    <div class="card-body">
        <div class="alert alert-subtle-info">{{ __('sales_ui.return_source_help') }}</div>
        <form method="GET" action="{{ route('admin.sales.sales-returns.create') }}" class="row g-3 align-items-end" data-return-source-form>
            <div class="col-md-9">
                <x-forms.label for="return_invoice_doc_num" :label="__('Sales Invoice')" required />
                <select class="form-select js-select2-ajax" id="return_invoice_doc_num" name="invoice_doc_num" data-url="{{ route('admin.sales.select2.returnable-invoices') }}" data-placeholder="{{ __('sales_ui.posted_invoice') }}" required></select>
            </div>
            <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">{{ __('sales_ui.continue_return') }}</button></div>
        </form>
    </div>
</div>
@endsection
