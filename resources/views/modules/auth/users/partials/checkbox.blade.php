@unless ($user->trashed())
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <x-forms.input class="form-check-input js-record-select js-user-row-checkbox" type="checkbox" value="{{ $user->doc_num }}" data-doc-num="{{ $user->doc_num }}" aria-label="{{ __('users.select_record', ['user' => $user->name]) }}" />
    </div>
@endunless
