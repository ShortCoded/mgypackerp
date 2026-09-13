@unless ($record->trashed())
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <x-forms.input class="form-check-input js-record-select js-price-list-row-checkbox" type="checkbox" value="{{ $record->doc_num }}" data-doc-num="{{ $record->doc_num }}" aria-label="{{ $record->doc_num }}" />
    </div>
@endunless
