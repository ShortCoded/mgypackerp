@unless ($role->trashed() || ($isProtectedRole ?? false))
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <input class="form-check-input role-row-checkbox js-role-row-checkbox js-record-select" type="checkbox" value="{{ $role->doc_num }}" data-doc-num="{{ $role->doc_num }}" aria-label="{{ __('auth.roles.selected') }}">
    </div>
@endunless
