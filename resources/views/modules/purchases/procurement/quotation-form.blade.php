@extends('layouts.app')
@section('title', __('Supplier Quotation Entry'))
@section('content')
@php($draft = $draft ?? null)
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
@php($sourceType = $sourceType ?? \Modules\Purchases\Models\SupplierQuotation::SourceRequestForQuotation)
@php($sourceLabel = match($sourceType) { \Modules\Purchases\Models\SupplierQuotation::SourcePurchaseRequisition => __('Purchase Request'), \Modules\Purchases\Models\SupplierQuotation::SourcePurchaseOrder => __('Purchase Order'), default => __('RFQ') })
<form method="POST" action="{{ $draft ? route('admin.purchases.supplier-quotation-entry.update', $draft->doc_num) : route('admin.purchases.supplier-quotation-entry.store-source', [$sourceType, $record->doc_num]) }}">
    @csrf @if($draft) @method('PUT') @endif
        <x-forms.line-item-cards />
    <div class="card mb-3">@include('modules.purchases.procurement.partials.form-toolbar', ['toolbarTitle' => __('Supplier Quotation for :document', ['document' => $record->doc_num]), 'toolbarPermission' => 'purchases.supplier_quotation_entry', 'toolbarRoute' => 'supplier-quotation-entry'])<div class="card-body">
        @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <div class="alert alert-info py-2 mb-3"><span class="fw-semibold">{{ __('Source document') }}:</span> {{ $sourceLabel }} / <span dir="ltr">{{ $record->doc_num }}</span></div>
        <div class="row g-3">
            <div class="col-12 col-md-6 col-xl-4"><label class="form-label">{{ __('Supplier') }}</label><select class="form-select js-select2-ajax" name="supplier_doc_num" required data-url="{{ route('admin.purchases.select2.suppliers', $sourceType === \Modules\Purchases\Models\SupplierQuotation::SourceRequestForQuotation ? ['rfq' => $record->doc_num] : []) }}" data-placeholder="{{ __('Select') }}"><option value="">{{ __('Select') }}</option>@foreach($selectedSuppliers as $supplier)<option value="{{ $supplier->doc_num }}" selected>{{ $supplier->doc_num }} / {{ $supplier->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">{{ __('Currency') }}</label><select class="form-select js-select2-ajax" name="currency_doc_num" data-url="{{ route('admin.purchases.select2.currencies') }}" data-placeholder="{{ __('Select') }}"><option value="">{{ __('Select') }}</option>@foreach($currencies as $currency)<option value="{{ $currency->doc_num }}" selected>{{ $currency->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">{{ __('Exchange rate') }}</label><x-forms.numeric-input name="exchange_rate" :scale="6" min="0.000001" step="0.000001"  :value="old('exchange_rate', $draft?->exchange_rate ?? 1)" required /></div>
            <div class="col-md-3"><label class="form-label">{{ __('Supplier reference') }}</label><input class="form-control" name="supplier_reference" value="{{ old('supplier_reference', $draft?->supplier_reference) }}"></div>
            <div class="col-md-3"><label class="form-label">{{ __('Quotation date') }}</label><input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" name="quotation_date" value="{{ old('quotation_date', $dates->formatDate($draft?->quotation_date ?? now())) }}" required></div>
            <div class="col-md-3"><label class="form-label">{{ __('Valid until') }}</label><input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" name="valid_until" value="{{ old('valid_until', $dates->formatDate($draft?->valid_until, '')) }}"></div>
            <div class="col-md-3"><label class="form-label">{{ __('Lead time (days)') }}</label><input class="form-control" type="number" min="0" name="lead_time_days" value="{{ old('lead_time_days', $draft?->lead_time_days) }}"></div>
            <div class="col-md-3"><label class="form-label">{{ __('Payment terms') }}</label><input class="form-control" name="payment_terms" value="{{ old('payment_terms', $draft?->payment_terms) }}"></div>
            <div class="col-md-3"><label class="form-label">{{ __('Freight') }}</label><x-forms.numeric-input name="freight_amount" :scale="4" min="0" step="0.0001" :value="old('freight_amount', $draft?->freight_amount ?? 0)" /></div>
            <div class="col-12"><label class="form-label">{{ __('Commercial notes') }}</label><textarea class="form-control" name="commercial_notes">{{ old('commercial_notes', $draft?->commercial_notes) }}</textarea></div>
        </div>
    </div></div>
    <div class="card mb-3"><div class="card-body p-0"><div class="table-responsive procurement-lines-scroll"><table class="table table-sm align-middle mb-0 procurement-lines-table"><thead class="bg-100"><tr><th>#</th><th>{{ __('Item') }}</th><th class="text-end">{{ __('Requested') }}</th><th>{{ __('Offered quantity') }}</th><th>{{ __('Unit price') }}</th><th>{{ __('Discount') }}</th><th>{{ __('Tax %') }}</th><th>{{ __('Delivery date') }}</th><th>{{ __('Notes') }}</th><th>{{ __('Attachments') }}</th></tr></thead><tbody>
        @foreach($record->lines as $index => $line)
            @php($sourceLineForeignKey = match($sourceType) { \Modules\Purchases\Models\SupplierQuotation::SourcePurchaseRequisition => 'purchase_requisition_line_id', \Modules\Purchases\Models\SupplierQuotation::SourcePurchaseOrder => 'purchase_order_line_id', default => 'request_for_quotation_line_id' })
            @php($sourceQuantity = match($sourceType) { \Modules\Purchases\Models\SupplierQuotation::SourcePurchaseRequisition => $line->approved_quantity, \Modules\Purchases\Models\SupplierQuotation::SourcePurchaseOrder => $line->ordered_quantity, default => $line->quantity })
            @php($draftLine = $draft?->lines->firstWhere($sourceLineForeignKey, $line->id))
            @continue(!$line->product?->isPurchasable())
            <tr><td>{{ $index + 1 }}</td><td>{{ $line->product?->doc_num }} / {{ $line->product?->name }}<input type="hidden" name="lines[{{ $index }}][source_line_public_id]" value="{{ $line->public_id }}"></td><td class="text-end" dir="ltr">{{ $sourceQuantity }}</td><td><x-forms.numeric-input name="lines[{{ $index }}][offered_quantity]" :scale="8" min="0.00000001" :max="$sourceQuantity" step="0.00000001" :value="old('lines.'.$index.'.offered_quantity', $draftLine?->offered_quantity ?? $sourceQuantity)" required /></td><td><x-forms.numeric-input name="lines[{{ $index }}][unit_price]" :scale="4" min="0" step="0.0001" :value="old('lines.'.$index.'.unit_price', $draftLine?->unit_price)" required /></td><td><x-forms.numeric-input name="lines[{{ $index }}][discount_amount]" :value="old('lines.'.$index.'.discount_amount', $draftLine?->discount_amount ?? 0)" :scale="4" min="0" step="0.0001" /></td><td><x-forms.numeric-input name="lines[{{ $index }}][tax_rate]" :value="old('lines.'.$index.'.tax_rate', $draftLine?->tax_rate ?? 0)" :scale="4" min="0" max="100" step="0.0001" /></td><td><input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" name="lines[{{ $index }}][delivery_date]" value="{{ old('lines.'.$index.'.delivery_date', $dates->formatDate($draftLine?->delivery_date, '')) }}"></td><td><input class="form-control" name="lines[{{ $index }}][notes]" value="{{ old('lines.'.$index.'.notes', $draftLine?->notes) }}"></td><td>@include('modules.purchases.procurement.line-attachments', ['attachmentLine' => $draftLine, 'attachmentCompanyId' => $draft?->company_id ?? $source->company_id, 'index' => $index])</td></tr>
        @endforeach
    </tbody></table></div></div></div>
    @include('modules.purchases.procurement.attachments', ['attachmentRecord' => $draft, 'attachmentCollection' => \Modules\Purchases\Models\SupplierQuotation::AttachmentCollection])
</form>
@endsection
@push('scripts')
    <script src="{{ asset('assets/js/modules/Purchases/procurement-cycle.js') }}"></script>
@endpush
