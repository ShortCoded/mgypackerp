@if ((! $employee->trashed() && (auth()->user()?->can('hr.employees.delete') || auth()->user()?->can('hr.employees.edit'))) || ($employee->trashed() && auth()->user()?->can('hr.employees.restore')))
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <input class="form-check-input js-hr-employees-row-checkbox js-record-select" type="checkbox" value="{{ $employee->doc_num }}" data-doc-num="{{ $employee->doc_num }}" aria-label="{{ __('hr.selected_records') }}">
    </div>
@endif
