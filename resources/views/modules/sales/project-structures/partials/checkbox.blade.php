@unless ($record->trashed())
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <input class="form-check-input js-record-select js-project-structure-row-checkbox" type="checkbox" value="{{ $record->doc_num }}" data-doc-num="{{ $record->doc_num }}" aria-label="{{ $record->name }}">
    </div>
@endunless
