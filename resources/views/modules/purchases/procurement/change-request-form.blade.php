@extends('layouts.app')
@section('title', __('Purchase Order Change Request'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@php($replaceLines = $record->canReplaceUnexecutedLines())
<form method="POST" action="{{ route('admin.purchases.purchase-order-change-requests.store', $record->doc_num) }}" @if($replaceLines) data-procurement-form data-lines-container="#purchase-change-lines" data-line-template="#purchase-change-line-template" @endif>
    @csrf
        <x-forms.line-item-cards />
    <div class="card mb-3"><div class="card-header py-2"><h5 class="mb-0">{{ __('Controlled Change for PO :document', ['document' => $record->doc_num]) }}</h5></div><div class="card-body">
        @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <div class="row g-3"><div class="col-md-3"><label class="form-label">{{ __('Request date') }}</label><x-forms.date-input name="request_date" :value="now()->toDateString()" required /></div><div class="col-md-3"><label class="form-label">{{ __('Expected delivery') }}</label><x-forms.date-input name="requested_values[expected_delivery_date]" :value="$record->expected_delivery_date?->toDateString()" /></div><div class="col-md-3"><label class="form-label">{{ __('Payment terms') }}</label><x-forms.input class="form-control" name="requested_values[payment_terms]" value="{{ $record->payment_terms }}" /></div><div class="col-md-3"><label class="form-label">{{ __('Reason') }}</label><x-forms.input class="form-control" name="reason" required /></div><div class="col-12"><label class="form-label">{{ __('Updated notes') }}</label><x-forms.input class="form-control" name="requested_values[notes]" value="{{ $record->notes }}" /></div></div>
    </div></div>
    <div class="mb-3"><x-forms.label for="requester_employee_id" :label="__('procurement.ui.requester_employee')" required /><x-forms.select class="form-select js-select2-ajax" id="requester_employee_id" name="requester_employee_id" data-url="{{ route('admin.purchases.select2.employees') }}" required /></div>
    @if($replaceLines)
    <x-forms.input type="hidden" name="requested_values[replace_lines]" value="1" />
    <div class="alert alert-info">{{ __('procurement.line_correction_help') }}</div>
    <div class="card mb-3"><div class="card-header d-flex justify-content-between"><h6>{{ __('Items') }}</h6><button class="btn btn-falcon-default btn-sm" type="button" data-add-procurement-line>{{ __('Add line') }}</button></div><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>#</th><th>{{ __('Item') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Requested quantity') }}</th><th>{{ __('Requested price') }}</th><th>{{ __('Required delivery') }}</th><th></th></tr></thead><tbody id="purchase-change-lines">
    @foreach(old('requested_values.lines', $record->lines->map(fn ($line) => ['public_id' => $line->public_id, 'product_doc_num' => $line->product?->doc_num, 'product_text' => $line->product?->name, 'unit_doc_num' => $line->unit?->doc_num, 'unit_text' => $line->unit?->name, 'ordered_quantity' => $line->ordered_quantity, 'unit_price' => $line->unit_price, 'required_delivery_date' => $line->required_delivery_date?->toDateString()])->all()) as $index => $line)
        @include('modules.purchases.procurement.partials.change-request-line')
    @endforeach
    </tbody></table></div></div>
    @else
    <div class="card mb-3"><div class="card-body p-0"><div class="table-responsive procurement-lines-scroll"><table class="table table-sm align-middle mb-0 procurement-lines-table"><thead class="bg-100"><tr><th>{{ __('Item') }}</th><th class="text-end">{{ __('Current quantity') }}</th><th>{{ __('Requested quantity') }}</th><th class="text-end">{{ __('Current price') }}</th><th>{{ __('Requested price') }}</th><th>{{ __('Required delivery') }}</th></tr></thead><tbody>
        @foreach($record->lines as $index => $line)<tr><td>{{ $line->product?->name }}<x-forms.input type="hidden" name="requested_values[lines][{{ $index }}][public_id]" value="{{ $line->public_id }}" /></td><td class="text-end" dir="ltr">{{ $numbers->format($line->ordered_quantity) }}</td><td><x-forms.numeric-input name="requested_values[lines][{{ $index }}][ordered_quantity]" :scale="8" min="0.00000001" step="0.00000001" :value="$line->ordered_quantity" /></td><td class="text-end" dir="ltr">{{ $numbers->format($line->unit_price) }}</td><td><x-forms.numeric-input name="requested_values[lines][{{ $index }}][unit_price]" :scale="8" min="0" step="0.00000001" :value="$line->unit_price" /></td><td><x-forms.date-input name="requested_values[lines][{{ $index }}][required_delivery_date]" :value="$line->required_delivery_date?->toDateString()" /></td></tr>@endforeach
    </tbody></table></div></div></div>
    @endif
    <div class="d-flex justify-content-end"><button class="btn btn-primary">{{ __('Submit change request') }}</button></div>
</form>
@if($replaceLines)<template id="purchase-change-line-template">@include('modules.purchases.procurement.partials.change-request-line', ['index' => '__INDEX__', 'line' => []])</template>@endif
@endsection
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Purchases/procurement-cycle.js') }}"></script>@endpush
