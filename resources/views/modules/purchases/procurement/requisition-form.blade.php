@extends('layouts.app')
@php
    $record ??= null;
    $mode = $record ? 'edit' : 'create';
    $title = $record ? __('Edit Purchase Requisition').' '.$record->doc_num : __('Create Purchase Requisition');
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $initialLines = $record?->lines->map(fn ($line) => [...$line->toArray(), 'product_doc_num' => $line->product?->doc_num, 'product_text' => $line->product?->doc_num.' / '.$line->product?->name, 'unit_doc_num' => $line->unit?->doc_num, 'unit_text' => $line->unit?->name, 'unit_options' => app(\Modules\Core\Services\ProductComponentUnitOptionsService::class)->options($line->product)])->all() ?? [[]];
@endphp
@section('title', $title)
@section('content')
<form method="POST" action="{{ $record ? route('admin.purchases.purchase-requisitions.update', $record->doc_num) : route('admin.purchases.purchase-requisitions.store') }}" data-procurement-form data-availability-url="{{ route('admin.purchases.purchase-requisitions.availability') }}" data-lines-container="#requisition-lines" data-line-template="#requisition-line-template">
    @csrf
    <x-forms.line-item-cards />
    @if($record) @method('PUT') @endif
    <input type="hidden" name="submit_action" value="save_view">
    <div class="card mb-3">
        <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="mb-0">{{ $title }}</h5>
            @include('modules.finance.partials.form-actions', ['resource' => 'purchases.purchase_requisitions', 'routePrefix' => 'admin.purchases.purchase-requisitions', 'canClone' => false, 'canDeleteRecord' => false])
        </div>
        <div class="card-body">
            @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div class="d-flex flex-wrap gap-4 mb-3 text-700 fs-10">
                <span>{{ __('Document Number') }}: <strong dir="ltr">{{ $record?->doc_num ?? __('Generated automatically') }}</strong></span>
                <span>{{ __('Branch') }}: <strong>{{ $branch->name }}</strong>@if($store) / {{ $store->name }}@endif</span>
            </div>
            <div class="row g-3">
                <div class="col-12 col-md-3"><x-forms.label for="request_date" :label="__('Request date')" required /><input id="request_date" class="form-control js-date-picker" type="text" name="request_date" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" value="{{ old('request_date', $dates->formatDate($record?->request_date ?? now())) }}" required></div>
                <div class="col-12 col-md-3"><x-forms.label for="branch_store_uuid" :label="__('Warehouse')" required /><select id="branch_store_uuid" class="form-select js-select2-ajax" name="branch_store_uuid" data-url="{{ route('admin.purchases.select2.branch-stores') }}" data-placeholder="{{ __('Select') }}" required>@if($store)<option selected value="{{ $store->public_uuid }}">{{ $store->name }}</option>@endif</select></div>
                <div class="col-12 col-md-3"><x-forms.label for="requester_employee_id" :label="__('procurement.ui.requester_employee')" required /><select id="requester_employee_id" class="form-select js-select2-ajax" name="requester_employee_id" data-url="{{ route('admin.purchases.select2.employees') }}" data-placeholder="{{ __('Select') }}" required>@if($employee)<option selected value="{{ $employee->id }}">{{ $employee->doc_num }} / {{ $employee->full_name ?: $employee->name }}</option>@endif</select></div>
                <div class="col-12 col-md-3"><label class="form-label" for="required_by_date">{{ __('Required by') }}</label><input id="required_by_date" class="form-control js-date-picker" type="text" name="required_by_date" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" value="{{ old('required_by_date', $dates->formatDate($record?->required_by_date, '')) }}"></div>
                <div class="col-12"><label class="form-label" for="notes">{{ __('Notes') }}</label><textarea id="notes" class="form-control" rows="2" name="notes">{{ old('notes', $record?->notes) }}</textarea></div>
            </div>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center"><h6 class="mb-0">{{ __('Requirement lines') }}</h6><button class="btn btn-falcon-primary btn-sm" type="button" data-add-procurement-line><span class="fas fa-plus me-1"></span>{{ __('Add line') }}</button></div>
        <div class="card-body p-0 table-responsive" role="region" aria-label="{{ __('Requirement lines') }}" tabindex="0"><table class="table table-sm align-middle mb-0"><thead><tr><th>#</th><th>{{ __('Item') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th><th></th><th>{{ __('Notes') }}</th><th>{{ __('Attachments') }}</th><th></th></tr></thead><tbody id="requisition-lines">
        @foreach(old('lines', $initialLines) as $index => $line)
            @include('modules.purchases.procurement.partials.requisition-line')
        @endforeach
        </tbody></table></div>
    </div>
    @include('modules.purchases.procurement.attachments', ['attachmentRecord' => $record])
</form>
<template id="requisition-line-template">@include('modules.purchases.procurement.partials.requisition-line', ['index' => '__INDEX__', 'line' => []])</template>
@endsection
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Purchases/procurement-cycle.js') }}"></script>@endpush
