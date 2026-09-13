@can('financial_periods.delete')
    @unless ($financialPeriod->trashed())
        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
            <x-forms.input class="form-check-input js-record-select js-record-checkbox" type="checkbox" value="{{ $financialPeriod->doc_num }}" data-doc-num="{{ $financialPeriod->doc_num }}" aria-label="{{ __('financial_periods.select_record', ['record' => $financialPeriod->doc_num]) }}" />
        </div>
    @endunless
@endcan
