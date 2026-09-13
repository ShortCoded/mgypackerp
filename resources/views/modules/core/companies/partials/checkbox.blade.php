@unless ($company->trashed())
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <x-forms.input class="form-check-input js-record-select js-company-row-checkbox" type="checkbox" value="{{ $company->doc_num }}" data-doc-num="{{ $company->doc_num }}" aria-label="{{ __('companies.select_record', ['company' => $company->name]) }}" />
    </div>
@endunless
