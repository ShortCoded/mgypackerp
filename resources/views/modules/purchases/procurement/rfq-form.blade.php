@extends('layouts.app')
@section('title', __('Create RFQ'))
@section('content')
<form method="POST" action="{{ route('admin.purchases.request-for-quotations.store', $record->doc_num) }}">
    @csrf
    <div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('Create RFQ from :document', ['document' => $record->doc_num]) }}</h5></div><div class="card-body">
        @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">{{ __('Issue date') }}</label><input class="form-control" type="date" name="issue_date" value="{{ old('issue_date', now()->toDateString()) }}" required></div>
            <div class="col-md-3"><label class="form-label">{{ __('Quotation due date') }}</label><input class="form-control" type="date" name="quotation_due_date" value="{{ old('quotation_due_date') }}"></div>
            <div class="col-md-3"><label class="form-label">{{ __('Required delivery') }}</label><input class="form-control" type="date" name="required_delivery_date" value="{{ old('required_delivery_date', $record->required_by_date?->toDateString()) }}"></div>
            <div class="col-md-3"><label class="form-label">{{ __('Invited suppliers') }}</label><select class="form-select js-select2" name="supplier_doc_nums[]" multiple required>@foreach($suppliers as $supplier)<option value="{{ $supplier->doc_num }}">{{ $supplier->doc_num }} / {{ $supplier->name }}</option>@endforeach</select></div>
            <div class="col-12"><label class="form-label">{{ __('Commercial notes') }}</label><textarea class="form-control" name="commercial_notes">{{ old('commercial_notes') }}</textarea></div>
        </div>
    </div></div>
    <div class="card mb-3"><div class="card-body p-0"><div class="table-responsive procurement-lines-scroll"><table class="table table-sm align-middle mb-0 procurement-lines-table"><thead class="bg-100"><tr><th>#</th><th>{{ __('Item') }}</th><th>{{ __('Source') }}</th><th class="text-end">{{ __('Approved') }}</th><th class="text-end">{{ __('RFQ quantity') }}</th><th>{{ __('Specification') }}</th></tr></thead><tbody>
        @foreach($record->lines->where('approved_quantity', '>', 0) as $index => $line)
            <tr><td>{{ $index + 1 }}</td><td>{{ $line->product?->name }}</td><td dir="ltr">{{ $line->source_doc_num ?: __('Manual') }}</td><td class="text-end" dir="ltr">{{ $line->approved_quantity }}</td><td><input type="hidden" name="lines[{{ $index }}][requisition_line_public_id]" value="{{ $line->public_id }}"><x-forms.numeric-input name="lines[{{ $index }}][quantity]" :scale="8" min="0.00000001" step="0.00000001" :value="$line->approved_quantity" required /></td><td>{{ $line->specification ?: '—' }}</td></tr>
        @endforeach
    </tbody></table></div></div></div>
    <div class="d-flex justify-content-end"><button class="btn btn-primary">{{ __('Save RFQ draft') }}</button></div>
</form>
@endsection
