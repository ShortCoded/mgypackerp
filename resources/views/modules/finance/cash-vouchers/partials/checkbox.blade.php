@if (! $record->trashed() && $record->isDeletable())
    <div class="form-check mb-0 d-flex justify-content-center">
        <input class="form-check-input js-record-select js-finance-row-checkbox" type="checkbox" value="{{ $record->doc_num }}" data-doc-num="{{ $record->doc_num }}" aria-label="{{ $record->doc_num }}">
    </div>
@endif
