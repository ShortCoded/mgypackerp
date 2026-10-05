@extends('layouts.app')
@section('title', __('Supplier Selection'))
@section('content')
@php($draft = $draft ?? null)
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<form method="POST" action="{{ $draft ? route('admin.purchases.supplier-selection.update', $draft) : route('admin.purchases.supplier-selection.store', $record->doc_num) }}">
    @csrf @if($draft) @method('PUT') @endif
        <x-forms.line-item-cards />
    <div class="card mb-3"><div class="card-header py-2"><h5 class="mb-0">{{ __('Supplier Selection for :document', ['document' => $record->doc_num]) }}</h5></div><div class="card-body">
        @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <div class="row g-3"><div class="col-md-3"><label class="form-label">{{ __('Selection date') }}</label><x-forms.date-input name="selection_date" :value="old('selection_date', $draft?->selection_date ?? now()->toDateString())" required /></div><div class="col-md-9"><label class="form-label">{{ __('Decision reason') }}</label><x-forms.input class="form-control" name="selection_reason" value="{{ old('selection_reason', $draft?->selection_reason) }}" placeholder="{{ __('Cheapest is highlighted, but the authorized decision remains yours.') }}" /></div></div>
    </div></div>
    <div class="card mb-3"><div class="card-body p-0"><div class="table-responsive procurement-lines-scroll"><table class="table table-sm align-middle mb-0 procurement-lines-table"><thead class="bg-100"><tr><th>{{ __('Item') }}</th><th>{{ __('Supplier') }}</th><th class="text-end">{{ __('Offered') }}</th><th>{{ __('Currency') }}</th><th class="text-end">{{ __('Unit price') }}</th><th class="text-end">{{ __('Line total') }}</th><th>{{ __('Discount') }}</th><th>{{ __('procurement.fields.commercial_header_discount') }}</th><th>{{ __('Award quantity') }}</th><th>{{ __('Reason') }}</th></tr></thead><tbody>
        @foreach($lines as $index => $line)
            @php($draftLine = $draft?->lines->firstWhere('supplier_quotation_line_id', $line->id))
            <tr data-selection-discount-line><td>{{ $line->rfqLine?->product?->name }}</td><td>{{ $line->quotation?->supplier?->name }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->offered_quantity) }}</td><td>{{ $line->quotation?->currency?->name ?: '—' }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->unit_price) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->line_total) }}</td><td><x-forms.input type="hidden" name="lines[{{ $index }}][inherit_source_discount]" value="{{ $draftLine ? (int) ($draftLine->source_discount_snapshot['inherited'] ?? false) : 1 }}" /><x-forms.select class="form-select form-select-sm" data-selection-discount-input name="lines[{{ $index }}][discount_type]">@foreach(['fixed', 'percentage'] as $mode)<option value="{{ $mode }}" @selected(old('lines.'.$index.'.discount_type', $draftLine?->discount_type ?? $line->discount_type ?? 'fixed') === $mode)>{{ __('purchase_orders.discount_types.'.$mode) }}</option>@endforeach</x-forms.select><x-forms.numeric-input data-selection-discount-input name="lines[{{ $index }}][discount_value]" :scale="4" min="0" step="0.0001" :value="old('lines.'.$index.'.discount_value', $draftLine?->discount_value ?? $draftLine?->discount_amount ?? $line->discount_value ?? $line->discount_amount)" />@if($draftLine)<small>{{ $numbers->format($draftLine->discount_amount) }}</small>@endif</td><td>@include('modules.purchases.procurement.partials.discount-details', ['discountType' => $line->quotation->header_discount_type, 'discountValue' => $line->quotation->header_discount_value, 'discountAmount' => $line->header_discount_amount])</td><td><x-forms.input type="hidden" name="lines[{{ $index }}][quotation_line_public_id]" value="{{ $line->public_id }}" /><x-forms.numeric-input name="lines[{{ $index }}][selected_quantity]" :scale="8" min="0.00000001" :max="$line->offered_quantity" step="0.00000001" :value="old('lines.'.$index.'.selected_quantity', $draftLine?->selected_quantity)" /></td><td><x-forms.input class="form-control" name="lines[{{ $index }}][reason]" value="{{ old('lines.'.$index.'.reason', $draftLine?->reason) }}" /></td></tr>
        @endforeach
    </tbody></table></div></div></div>
    <div class="d-flex justify-content-end"><button class="btn btn-primary">{{ __('Save selection draft') }}</button></div>
</form>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/modules/Purchases/procurement-cycle.js') }}"></script>
@endpush
