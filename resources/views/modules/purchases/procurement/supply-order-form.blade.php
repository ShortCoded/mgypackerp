@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $sourceType = $source instanceof \Modules\Purchases\Models\PurchaseInvoice
        ? \Modules\Purchases\Models\SupplyOrder::SourcePurchaseInvoice
        : \Modules\Purchases\Models\SupplyOrder::SourcePurchaseOrder;
    $sourceOrder = $source instanceof \Modules\Purchases\Models\PurchaseOrder ? $source : $source->purchaseOrder;
    $title = $record ? __('Edit Supply Order').' '.$record->doc_num : __('Create Supply Order');
@endphp

@section('title', $title)

@section('content')
<form method="POST" action="{{ $record ? route('admin.purchases.supply-orders.update', $record) : route('admin.purchases.supply-orders.store') }}">
    @csrf
    @if($record) @method('PUT') @endif
    <input type="hidden" name="submit_action" value="save_view">
    <input type="hidden" name="source_type" value="{{ $sourceType }}">
    <input type="hidden" name="source_doc_num" value="{{ $source->doc_num }}">

    <div class="card mb-3">
        <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="mb-1">{{ $title }}</h5>
                <div class="text-600 fs-10">{{ __('Source') }}: <strong dir="ltr">{{ $source->doc_num }}</strong></div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.supply-orders.index') }}">{{ __('common.actions.back') }}</a>
                <button class="btn btn-falcon-primary btn-sm" type="submit"><span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}</button>
            </div>
        </div>
        <div class="card-body">
            @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">{{ __('Supply Order Number') }}</label><input class="form-control" value="{{ $record?->doc_num ?? __('Generated automatically') }}" dir="ltr" readonly></div>
                <div class="col-md-3"><label class="form-label" for="issue_date">{{ __('Issue date') }}</label><input class="form-control js-date-picker" id="issue_date" name="issue_date" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" value="{{ old('issue_date', $dates->formatDate($record?->issue_date ?? now())) }}" required></div>
                <div class="col-md-3"><label class="form-label" for="expected_delivery_date">{{ __('Expected delivery date') }}</label><input class="form-control js-date-picker" id="expected_delivery_date" name="expected_delivery_date" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" value="{{ old('expected_delivery_date', $dates->formatDate($record?->expected_delivery_date ?? $sourceOrder?->expected_delivery_date, '')) }}"></div>
                <div class="col-md-3"><label class="form-label">{{ __('Supplier') }}</label><input class="form-control" value="{{ $source->supplier?->name }}" readonly></div>
                <div class="col-md-6"><label class="form-label">{{ __('Receiving Warehouse') }}</label><input class="form-control" value="{{ $sourceOrder?->branchStore?->name }}" readonly></div>
                <div class="col-md-6"><label class="form-label" for="notes">{{ __('Notes') }}</label><input class="form-control" id="notes" name="notes" value="{{ old('notes', $record?->notes) }}"></div>
            </div>
        </div>
    </div>

    <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">{{ __('Supply Order Lines') }}</h6>
            <span class="text-600 fs-10">{{ __('Enter only the quantity authorized for this supply order.') }}</span>
        </div>
        <div class="row g-3">
            @foreach($sourceLines as $index => $line)
                @php
                    $savedLine = $record?->lines->firstWhere('purchase_order_line_id', $line->getKey());
                    $remaining = (float) $line->getAttribute('supply_available_quantity');
                    $quantity = old('lines.'.$index.'.ordered_quantity', $savedLine?->ordered_quantity ?? $remaining);
                @endphp
                <div class="col-12">
                    <div class="card border shadow-none">
                        <div class="card-header py-2 d-flex flex-wrap justify-content-between gap-2">
                            <strong>{{ __('Line') }} #{{ $index + 1 }} — {{ $line->product?->doc_num }} / {{ $line->product?->name }}</strong>
                            <span class="text-600 fs-10">{{ __('Available from source') }}: <span dir="ltr">{{ $numbers->format($remaining) }}</span></span>
                        </div>
                        <div class="card-body">
                            <input type="hidden" name="lines[{{ $index }}][purchase_order_line_public_id]" value="{{ $line->public_id }}">
                            <div class="row g-3 align-items-end">
                                <div class="col-12 col-md-5"><label class="form-label">{{ __('Item') }}</label><input class="form-control" value="{{ $line->product?->doc_num }} / {{ $line->product?->name }}" readonly></div>
                                <div class="col-6 col-md-2"><label class="form-label">{{ __('Unit') }}</label><input class="form-control" value="{{ $line->unit?->name }}" readonly></div>
                                <div class="col-6 col-md-2"><label class="form-label">{{ __('PO Remaining') }}</label><input class="form-control" value="{{ $numbers->format($line->quantityProgress()['remaining']) }}" dir="ltr" readonly></div>
                                <div class="col-12 col-md-3"><label class="form-label" for="supply_quantity_{{ $index }}">{{ __('Supply quantity') }}</label><x-forms.numeric-input id="supply_quantity_{{ $index }}" name="lines[{{ $index }}][ordered_quantity]" :scale="8" min="0" step="0.00000001" :value="$quantity" required /></div>
                                <div class="col-12"><label class="form-label" for="supply_notes_{{ $index }}">{{ __('Notes') }}</label><input class="form-control" id="supply_notes_{{ $index }}" name="lines[{{ $index }}][notes]" value="{{ old('lines.'.$index.'.notes', $savedLine?->notes) }}"></div>
                                <div class="col-12">
                                    <label class="form-label">{{ __('Attachments') }}</label>
                                    @include('modules.purchases.procurement.line-attachments', ['attachmentLine' => $savedLine, 'attachmentCompanyId' => $record?->company_id ?? $sourceOrder->company_id, 'index' => $index])
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    @include('modules.purchases.procurement.attachments', ['attachmentRecord' => $record])
</form>
@endsection
