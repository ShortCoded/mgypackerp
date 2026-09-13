@extends('layouts.app')
@section('title', __('Create RFQ'))
@section('content')
@php($draft = $draft ?? null)
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<form method="POST" action="{{ $draft ? route('admin.purchases.request-for-quotations.update', $draft->doc_num) : route('admin.purchases.request-for-quotations.store', $record->doc_num) }}">
    @csrf @if($draft) @method('PUT') @endif
        <x-forms.line-item-cards />
    <div class="card mb-3">@include('modules.purchases.procurement.partials.form-toolbar', ['toolbarTitle' => __('Create RFQ from :document', ['document' => $record->doc_num]), 'toolbarPermission' => 'purchases.request_for_quotations', 'toolbarRoute' => 'request-for-quotations'])<div class="card-body">
        @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">{{ __('Issue date') }}</label><x-forms.date-input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" name="issue_date" value="{{ old('issue_date', $dates->formatDate($draft?->issue_date ?? now())) }}" required /></div>
            <div class="col-md-3"><label class="form-label">{{ __('Quotation due date') }}</label><x-forms.date-input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" name="quotation_due_date" value="{{ old('quotation_due_date', $dates->formatDate($draft?->quotation_due_date, '')) }}" /></div>
            <div class="col-md-3"><label class="form-label">{{ __('Required delivery') }}</label><x-forms.date-input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" name="required_delivery_date" value="{{ old('required_delivery_date', $dates->formatDate($draft?->required_delivery_date ?? $record->required_by_date, '')) }}" /></div>
            <div class="col-md-3"><label class="form-label">{{ __('Invited suppliers') }}</label><x-forms.select class="form-select js-select2-ajax" name="supplier_doc_nums[]" multiple required data-url="{{ route('admin.purchases.select2.suppliers') }}" data-placeholder="{{ __('Select') }}">@foreach($suppliers as $supplier)<option value="{{ $supplier->doc_num }}" selected>{{ $supplier->doc_num }} / {{ $supplier->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-12"><label class="form-label">{{ __('Commercial notes') }}</label><x-forms.textarea class="form-control" name="commercial_notes">{{ old('commercial_notes', $draft?->commercial_notes) }}</x-forms.textarea></div>
        </div>
    </div></div>
    <div class="card mb-3"><div class="card-body p-0"><div class="table-responsive procurement-lines-scroll"><table class="table table-sm align-middle mb-0 procurement-lines-table"><thead class="bg-100"><tr><th>#</th><th>{{ __('Item') }}</th><th>{{ __('Source') }}</th><th class="text-end">{{ __('Approved') }}</th><th class="text-end">{{ __('RFQ quantity') }}</th><th>{{ __('Specification') }}</th><th>{{ __('Attachments') }}</th></tr></thead><tbody>
        @foreach($record->lines->where('approved_quantity', '>', 0) as $index => $line)
            @php($draftLine = $draft?->lines->firstWhere('purchase_requisition_line_id', $line->id))
            <tr><td>{{ $index + 1 }}</td><td>{{ $line->product?->name }}</td><td dir="ltr" @if(!$line->source_doc_num) hidden @endif>{{ $line->source_doc_num }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->approved_quantity) }}</td><td><x-forms.input type="hidden" name="lines[{{ $index }}][requisition_line_public_id]" value="{{ $line->public_id }}" /><x-forms.numeric-input name="lines[{{ $index }}][quantity]" :scale="8" min="0.00000001" step="0.00000001" :value="old('lines.'.$index.'.quantity', $draftLine?->quantity ?? $line->approved_quantity)" required /></td><td @if(!$line->specification) hidden @endif>{{ $line->specification }}</td><td>@include('modules.purchases.procurement.line-attachments', ['attachmentLine' => $draftLine, 'attachmentCompanyId' => $draft?->company_id ?? $record->company_id, 'index' => $index])</td></tr>
        @endforeach
    </tbody></table></div></div></div>
    @include('modules.purchases.procurement.attachments', ['attachmentRecord' => $draft])
</form>
@endsection
