@extends('layouts.app')
@section('title', __('Purchase Order Change Request'))
@section('content')
<form method="POST" action="{{ route('admin.purchases.purchase-order-change-requests.store', $record->doc_num) }}">
    @csrf
    <div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('Controlled Change for PO :document', ['document' => $record->doc_num]) }}</h5></div><div class="card-body">
        @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <div class="row g-3"><div class="col-md-3"><label class="form-label">{{ __('Request date') }}</label><input class="form-control" type="date" name="request_date" value="{{ now()->toDateString() }}" required></div><div class="col-md-3"><label class="form-label">{{ __('Expected delivery') }}</label><input class="form-control" type="date" name="requested_values[expected_delivery_date]" value="{{ $record->expected_delivery_date?->toDateString() }}"></div><div class="col-md-3"><label class="form-label">{{ __('Payment terms') }}</label><input class="form-control" name="requested_values[payment_terms]" value="{{ $record->payment_terms }}"></div><div class="col-md-3"><label class="form-label">{{ __('Reason') }}</label><input class="form-control" name="reason" required></div><div class="col-12"><label class="form-label">{{ __('Updated notes') }}</label><input class="form-control" name="requested_values[notes]" value="{{ $record->notes }}"></div></div>
    </div></div>
    <div class="card mb-3"><div class="card-body p-0"><div class="table-responsive procurement-lines-scroll"><table class="table table-sm align-middle mb-0 procurement-lines-table"><thead class="bg-100"><tr><th>{{ __('Item') }}</th><th class="text-end">{{ __('Current quantity') }}</th><th>{{ __('Requested quantity') }}</th><th class="text-end">{{ __('Current price') }}</th><th>{{ __('Requested price') }}</th><th>{{ __('Required delivery') }}</th></tr></thead><tbody>
        @foreach($record->lines as $index => $line)<tr><td>{{ $line->product?->name }}<input type="hidden" name="requested_values[lines][{{ $index }}][public_id]" value="{{ $line->public_id }}"></td><td class="text-end" dir="ltr">{{ $line->ordered_quantity }}</td><td><x-forms.numeric-input name="requested_values[lines][{{ $index }}][ordered_quantity]" :scale="8" min="0.00000001" step="0.00000001" :value="$line->ordered_quantity" /></td><td class="text-end" dir="ltr">{{ $line->unit_price }}</td><td><x-forms.numeric-input name="requested_values[lines][{{ $index }}][unit_price]" :scale="4" min="0" step="0.0001" :value="$line->unit_price" /></td><td><input class="form-control" type="date" name="requested_values[lines][{{ $index }}][required_delivery_date]" value="{{ $line->required_delivery_date?->toDateString() }}"></td></tr>@endforeach
    </tbody></table></div></div></div>
    <div class="d-flex justify-content-end"><button class="btn btn-primary">{{ __('Submit change request') }}</button></div>
</form>
@endsection
