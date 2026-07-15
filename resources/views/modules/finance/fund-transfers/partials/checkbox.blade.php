@if($record->doc_num !== null)
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <input class="form-check-input js-record-select" type="checkbox" value="{{ $record->doc_num }}" data-doc-num="{{ $record->doc_num }}" aria-label="{{ $record->doc_num }}">
    </div>
@endif
