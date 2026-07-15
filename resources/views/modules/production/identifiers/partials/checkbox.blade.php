@unless ($identifier->trashed())
    <div class="mb-0 form-check d-flex align-items-center justify-content-center">
        <input class="form-check-input js-record-select js-production-identifier-row-checkbox" type="checkbox" value="{{ $identifier->doc_num }}" data-doc-num="{{ $identifier->doc_num }}" aria-label="{{ $identifier->name }}">
    </div>
@endunless
