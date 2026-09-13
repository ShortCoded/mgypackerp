@extends('layouts.app')

@section('title', __('Goods Receipt Note'))

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $draft = $draft ?? null;
@endphp

@section('content')
<form method="POST" action="{{ $draft ? route('admin.purchases.goods-receipt-notes.update', $draft->doc_num) : route('admin.purchases.goods-receipt-notes.store', $record->doc_num) }}">
    @csrf
    <x-forms.line-item-cards />
    @if($draft) @method('PUT') @endif

    <div class="card mb-3">
        @include('modules.purchases.procurement.partials.form-toolbar', [
            'toolbarTitle' => __('Receive Supply Order :document', ['document' => $record->doc_num]),
            'toolbarPermission' => 'purchases.goods_receipt_notes',
            'toolbarRoute' => 'goods-receipt-notes',
        ])
        <div class="card-body">
            @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
            <div class="alert alert-info py-2 mb-3">
                {{ __('This receipt updates inventory only when posted.') }}
                <a class="alert-link ms-1" href="{{ route('admin.purchases.supply-orders.show', $record) }}">{{ $record->doc_num }}</a>
                <span class="mx-1">←</span>
                <a class="alert-link" href="{{ route('admin.purchases.purchase-orders.show', $record->purchaseOrder) }}">{{ $record->purchaseOrder?->doc_num }}</a>
            </div>
            <div class="row g-3">
                <div class="col-12 col-md-3"><label class="form-label">{{ __('Supply Order') }}</label><x-forms.input class="form-control" value="{{ $record->doc_num }}" dir="ltr" readonly /></div>
                <div class="col-12 col-md-3"><label class="form-label">{{ __('Purchase Order') }}</label><x-forms.input class="form-control" value="{{ $record->purchaseOrder?->doc_num }}" dir="ltr" readonly /></div>
                <div class="col-12 col-md-3"><label class="form-label">{{ __('Supplier') }}</label><x-forms.input class="form-control" value="{{ $record->supplier?->name }}" readonly /></div>
                <div class="col-12 col-md-3"><label class="form-label">{{ __('Destination') }}</label><x-forms.input class="form-control" value="{{ $record->branchStore?->name }}" readonly /></div>
                <div class="col-12 col-md-3"><label class="form-label" for="document_date">{{ __('Receipt date') }}</label><x-forms.date-input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" id="document_date" name="document_date" value="{{ old('document_date', $dates->formatDate($draft?->document_date ?? now())) }}" required /></div>
                <div class="col-12 col-md-3"><label class="form-label" for="supplier_delivery_note">{{ __('Supplier delivery note') }}</label><x-forms.input class="form-control" id="supplier_delivery_note" name="supplier_delivery_note" value="{{ old('supplier_delivery_note', $draft?->supplier_delivery_note) }}" /></div>
                <div class="col-12 col-md-3"><label class="form-label" for="supplier_delivery_date">{{ __('Supplier delivery date') }}</label><x-forms.date-input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" id="supplier_delivery_date" name="supplier_delivery_date" value="{{ old('supplier_delivery_date', $dates->formatDate($draft?->reference_date, '')) }}" /></div>
                <div class="col-12 col-md-3"><label class="form-label" for="notes">{{ __('Notes') }}</label><x-forms.input class="form-control" id="notes" name="notes" value="{{ old('notes', $draft?->notes) }}" /></div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h6 class="mb-0">{{ __('Receipt Lines') }}</h6>
        <span class="text-600 fs-10">{{ __('Only outstanding quantities are shown.') }}</span>
    </div>
    <div class="row g-3 mb-3">
        @foreach($record->lines as $index => $line)
            @php
                $draftLine = $draft?->lines->firstWhere('supply_order_line_id', $line->getKey());
                $previouslyReceived = $line->receivedQuantity($draft?->getKey(), true);
                $remaining = max(0, (float) $line->ordered_quantity - $previouslyReceived);
            @endphp
            @continue($remaining <= 0 && !$draftLine)
            <div class="col-12">
                <div class="card border shadow-none h-100">
                    <div class="card-header py-2 d-flex flex-wrap justify-content-between gap-2">
                        <strong>{{ $line->product?->doc_num }} / {{ $line->product?->name }}</strong>
                        <span class="text-600 small">{{ $line->unit?->name }}</span>
                    </div>
                    <div class="card-body">
                        <x-forms.input type="hidden" name="lines[{{ $index }}][supply_order_line_public_id]" value="{{ $line->public_id }}" />
                        <x-forms.input type="hidden" name="lines[{{ $index }}][purchase_order_line_public_id]" value="{{ $line->purchaseOrderLine?->public_id }}" />
                        <div class="row g-3 align-items-end">
                            <div class="col-6 col-lg-2"><label class="form-label">{{ __('Supply ordered') }}</label><x-forms.input class="form-control" value="{{ $numbers->format($line->ordered_quantity) }}" dir="ltr" readonly /></div>
                            <div class="col-6 col-lg-2"><label class="form-label">{{ __('Previously received') }}</label><x-forms.input class="form-control" value="{{ $numbers->format($previouslyReceived) }}" dir="ltr" readonly /></div>
                            <div class="col-6 col-lg-2"><label class="form-label">{{ __('Remaining') }}</label><x-forms.input class="form-control" value="{{ $numbers->format($remaining) }}" dir="ltr" readonly /></div>
                            <div class="col-6 col-lg-2"><label class="form-label">{{ __('QC') }}</label><x-forms.input class="form-control" value="{{ $line->product?->requiresIncomingInspection() ? __('Inspection required') : __('Auto accepted') }}" readonly /></div>
                            <div class="col-12 col-lg-4"><label class="form-label" for="delivered_quantity_{{ $index }}">{{ __('Received now') }}</label><x-forms.numeric-input id="delivered_quantity_{{ $index }}" name="lines[{{ $index }}][delivered_quantity]" :scale="8" min="0" step="0.00000001" :value="old('lines.'.$index.'.delivered_quantity', $draftLine?->delivered_quantity ?? $remaining)" /></div>

                            @if($line->purchaseOrderLine?->deliverySchedules?->whereNotIn('status', ['received', 'cancelled'])->isNotEmpty())
                                <div class="col-12 col-lg-3"><label class="form-label">{{ __('Schedule') }}</label><x-forms.select class="form-select js-select2-local" name="lines[{{ $index }}][delivery_schedule_public_id]"><option value="">{{ __('Unscheduled') }}</option>@foreach($line->purchaseOrderLine->deliverySchedules->whereNotIn('status', ['received', 'cancelled']) as $schedule)<option value="{{ $schedule->public_id }}" @selected(old('lines.'.$index.'.delivery_schedule_public_id', $draftLine?->deliverySchedule?->public_id) === $schedule->public_id)>{{ $dates->formatDate($schedule->scheduled_date) }} / {{ $numbers->format((float) $schedule->scheduled_quantity - (float) $schedule->received_quantity) }}</option>@endforeach</x-forms.select></div>
                            @endif
                            <div class="col-12 col-lg-3"><label class="form-label">{{ __('Supplier lot') }}</label><x-forms.input class="form-control" name="lines[{{ $index }}][supplier_lot_number]" value="{{ old('lines.'.$index.'.supplier_lot_number', $draftLine?->supplier_lot_number) }}" /></div>
                            @if($line->product?->tracks_expiry || $draftLine?->expiry_date)
                                <div class="col-6 col-lg-2"><label class="form-label">{{ __('Manufacture date') }}</label><x-forms.date-input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" name="lines[{{ $index }}][manufacture_date]" value="{{ old('lines.'.$index.'.manufacture_date', $dates->formatDate($draftLine?->manufacture_date, '')) }}" /></div>
                                <div class="col-6 col-lg-2"><label class="form-label">{{ __('Expiry date') }}</label><x-forms.date-input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" name="lines[{{ $index }}][expiry_date]" value="{{ old('lines.'.$index.'.expiry_date', $dates->formatDate($draftLine?->expiry_date, '')) }}" /></div>
                            @endif
                            <div class="col-12 col-lg"><label class="form-label">{{ __('Notes') }}</label><x-forms.input class="form-control" name="lines[{{ $index }}][notes]" value="{{ old('lines.'.$index.'.notes', $draftLine?->notes) }}" /></div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @include('modules.purchases.procurement.attachments', ['attachmentRecord' => $draft])
</form>
@endsection
