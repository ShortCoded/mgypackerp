@unless ($costCenter->trashed() || $costCenter->isProtectedRoot())
    <div class="mb-0 form-check d-flex align-items-center justify-content-center">
        <x-forms.input class="form-check-input js-record-select js-cost-center-row-checkbox" type="checkbox" value="{{ $costCenter->doc_num }}" data-doc-num="{{ $costCenter->doc_num }}" aria-label="{{ $costCenter->name }}" />
    </div>
@endunless
