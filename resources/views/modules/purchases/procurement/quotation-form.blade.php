@extends('layouts.app')
@section('title', __('Supplier Quotation Entry'))
@section('content')
<form method="POST" action="{{ route('admin.purchases.supplier-quotation-entry.store', $record->doc_num) }}">
    @csrf
    <div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('Supplier Quotation for :document', ['document' => $record->doc_num]) }}</h5></div><div class="card-body">
        @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">{{ __('Supplier') }}</label><select class="form-select" name="supplier_doc_num" required><option value="">{{ __('Select') }}</option>@foreach($record->suppliers as $supplier)<option value="{{ $supplier->doc_num }}">{{ $supplier->doc_num }} / {{ $supplier->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">{{ __('Currency') }}</label><select class="form-select" name="currency_doc_num"><option value="">{{ __('Select') }}</option>@foreach($currencies as $currency)<option value="{{ $currency->doc_num }}">{{ $currency->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">{{ __('Exchange rate') }}</label><x-forms.numeric-input name="exchange_rate" :scale="6" min="0.000001" step="0.000001" value="1" required /></div>
            <div class="col-md-3"><label class="form-label">{{ __('Supplier reference') }}</label><input class="form-control" name="supplier_reference"></div>
            <div class="col-md-3"><label class="form-label">{{ __('Quotation date') }}</label><input class="form-control" type="date" name="quotation_date" value="{{ now()->toDateString() }}" required></div>
            <div class="col-md-3"><label class="form-label">{{ __('Valid until') }}</label><input class="form-control" type="date" name="valid_until"></div>
            <div class="col-md-3"><label class="form-label">{{ __('Lead time (days)') }}</label><input class="form-control" type="number" min="0" name="lead_time_days"></div>
            <div class="col-md-3"><label class="form-label">{{ __('Payment terms') }}</label><input class="form-control" name="payment_terms"></div>
            <div class="col-md-3"><label class="form-label">{{ __('Freight') }}</label><x-forms.numeric-input name="freight_amount" :scale="4" min="0" step="0.0001" value="0" /></div>
            <div class="col-12"><label class="form-label">{{ __('Commercial notes') }}</label><textarea class="form-control" name="commercial_notes"></textarea></div>
        </div>
    </div></div>
    <div class="card mb-3"><div class="card-body p-0"><div class="table-responsive procurement-lines-scroll"><table class="table table-sm align-middle mb-0 procurement-lines-table"><thead class="bg-100"><tr><th>#</th><th>{{ __('Item') }}</th><th class="text-end">{{ __('Requested') }}</th><th>{{ __('Offered quantity') }}</th><th>{{ __('Unit price') }}</th><th>{{ __('Discount') }}</th><th>{{ __('Tax %') }}</th><th>{{ __('Delivery date') }}</th><th>{{ __('Notes') }}</th></tr></thead><tbody>
        @foreach($record->lines as $index => $line)
            <tr><td>{{ $index + 1 }}</td><td>{{ $line->product?->name }}<input type="hidden" name="lines[{{ $index }}][rfq_line_public_id]" value="{{ $line->public_id }}"></td><td class="text-end" dir="ltr">{{ $line->quantity }}</td><td><x-forms.numeric-input name="lines[{{ $index }}][offered_quantity]" :scale="8" min="0.00000001" step="0.00000001" :value="$line->quantity" required /></td><td><x-forms.numeric-input name="lines[{{ $index }}][unit_price]" :scale="4" min="0" step="0.0001" required /></td><td><x-forms.numeric-input name="lines[{{ $index }}][discount_amount]" :scale="4" min="0" step="0.0001" value="0" /></td><td><x-forms.numeric-input name="lines[{{ $index }}][tax_rate]" :scale="4" min="0" max="100" step="0.0001" value="0" /></td><td><input class="form-control" type="date" name="lines[{{ $index }}][delivery_date]"></td><td><input class="form-control" name="lines[{{ $index }}][notes]"></td></tr>
        @endforeach
    </tbody></table></div></div></div>
    @can('file_manager.view')
        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Quotation attachments') }}</h6></div><div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span class="text-600">{{ __('Attach supplier quotation documents from the company archive.') }}</span>
                <button type="button" class="btn btn-falcon-primary btn-sm js-procurement-attachment-picker" data-file-picker data-picker-accept="document" data-picker-max="1" data-picker-title="{{ __('Choose attachment') }}" data-picker-collection="supplier_quotation_attachments" data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}" data-picker-allow-create-folder="{{ auth()->user()?->can('file_manager.folders.create') ? 'true' : 'false' }}">
                    <span class="fas fa-paperclip me-1"></span>{{ __('Choose attachment') }}
                </button>
            </div>
            <div class="js-procurement-attachment-inputs"></div>
            <ul class="list-group list-group-flush mt-3 d-none js-procurement-selected-attachments"></ul>
        </div></div>
        <x-file-picker-modal />
    @endcan
    <div class="d-flex justify-content-end"><button class="btn btn-primary">{{ __('Save quotation draft') }}</button></div>
</form>
@endsection
@push('scripts')
    @can('file_manager.view')
        <script src="{{ asset('assets/js/modules/Core/file-picker.js') }}"></script>
    @endcan
    <script src="{{ asset('assets/js/modules/Purchases/procurement-cycle.js') }}"></script>
@endpush
