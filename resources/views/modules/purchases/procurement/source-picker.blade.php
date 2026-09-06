@extends('layouts.app')
@section('title', $title)
@section('content')
@php
    $isReceiptSource = $destination === 'goods-receipt-notes';
    $sourceLabel = $isReceiptSource ? __('Supply Order') : __('Source document');
    $sourceHelp = $isReceiptSource
        ? __('Select an issued supply order; remaining quantities are loaded automatically.')
        : __('Select the source document to load its lines automatically.');
@endphp

<div class="card shadow-none border">
    <div class="card-header py-2 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div>
            <h5 class="mb-1">{{ $title }}</h5>
            <p class="text-600 fs-10 mb-0">{{ $sourceHelp }}</p>
        </div>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.'.$destination.'.index') }}">
            <span class="fas fa-arrow-right me-1"></span>{{ __('common.actions.back') }}
        </a>
    </div>
    <div class="card-body">
        <form
            data-procurement-source-picker
            data-destination="{{ route('admin.purchases.'.$destination.'.create', '__DOCUMENT__') }}"
            class="row g-3 align-items-end"
        >
            <div class="col-12 col-lg-8">
                <label class="form-label" for="source_document">{{ $sourceLabel }}</label>
                <select
                    id="source_document"
                    class="form-select js-select2-ajax"
                    data-url="{{ route('admin.purchases.select2.'.$lookup, ['purpose' => $isReceiptSource ? 'receipt' : null]) }}"
                    data-placeholder="{{ __('Select') }}"
                    aria-describedby="source_document_help"
                    required
                ></select>
                <div class="form-text" id="source_document_help">{{ $sourceHelp }}</div>
            </div>
            <div class="col-12 col-lg-4 d-grid">
                <button class="btn btn-primary" type="submit">
                    <span class="fas fa-list-check me-1"></span>{{ __('procurement.ui.load_lines') }}
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Purchases/procurement-cycle.js') }}"></script>@endpush
