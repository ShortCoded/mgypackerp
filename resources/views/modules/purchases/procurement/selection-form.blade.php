@extends('layouts.app')
@section('title', __('Supplier Selection'))
@section('content')
<form method="POST" action="{{ route('admin.purchases.supplier-selection.store', $record->doc_num) }}">
    @csrf
    <div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('Supplier Selection for :document', ['document' => $record->doc_num]) }}</h5></div><div class="card-body">
        @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <div class="row g-3"><div class="col-md-3"><label class="form-label">{{ __('Selection date') }}</label><input class="form-control" type="date" name="selection_date" value="{{ now()->toDateString() }}" required></div><div class="col-md-9"><label class="form-label">{{ __('Decision reason') }}</label><input class="form-control" name="selection_reason" placeholder="{{ __('Cheapest is highlighted, but the authorized decision remains yours.') }}"></div></div>
    </div></div>
    <div class="card mb-3"><div class="card-body p-0"><div class="table-responsive procurement-lines-scroll"><table class="table table-sm align-middle mb-0 procurement-lines-table"><thead class="bg-100"><tr><th>{{ __('Item') }}</th><th>{{ __('Supplier') }}</th><th class="text-end">{{ __('Offered') }}</th><th>{{ __('Currency') }}</th><th class="text-end">{{ __('Unit price') }}</th><th class="text-end">{{ __('Line total') }}</th><th>{{ __('Award quantity') }}</th><th>{{ __('Reason') }}</th></tr></thead><tbody>
        @foreach($lines as $index => $line)
            <tr><td>{{ $line->rfqLine?->product?->name }}</td><td>{{ $line->quotation?->supplier?->name }}</td><td class="text-end" dir="ltr">{{ $line->offered_quantity }}</td><td>{{ $line->quotation?->currency?->name ?: '—' }}</td><td class="text-end" dir="ltr">{{ $line->unit_price }}</td><td class="text-end" dir="ltr">{{ $line->line_total }}</td><td><input type="hidden" name="lines[{{ $index }}][quotation_line_public_id]" value="{{ $line->public_id }}"><x-forms.numeric-input name="lines[{{ $index }}][selected_quantity]" :scale="8" min="0.00000001" step="0.00000001" /></td><td><input class="form-control" name="lines[{{ $index }}][reason]"></td></tr>
        @endforeach
    </tbody></table></div></div></div>
    <div class="d-flex justify-content-end"><button class="btn btn-primary">{{ __('Save selection draft') }}</button></div>
</form>
@endsection
