@if(! $record->trashed())
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <x-forms.input class="form-check-input js-record-select" type="checkbox" value="{{ $record->doc_num }}" data-doc-num="{{ $record->doc_num }}" aria-label="{{ __('purchase_invoices.select_record', ['doc' => $record->doc_num]) }}" />
    </div>
@endif
