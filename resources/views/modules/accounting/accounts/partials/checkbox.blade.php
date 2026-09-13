@if (! $account->trashed() && ! $account->isProtectedRoot())
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <x-forms.input class="form-check-input js-record-select js-account-row-checkbox" type="checkbox" value="{{ $account->doc_num }}" data-doc-num="{{ $account->doc_num }}" aria-label="{{ $account->account_code }}" />
    </div>
@endif
