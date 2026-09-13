@if ((! $employee->trashed() && (auth()->user()?->can('hr.employees.delete') || auth()->user()?->can('hr.employees.edit'))) || ($employee->trashed() && auth()->user()?->can('hr.employees.restore')))
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <x-forms.input class="form-check-input js-hr-employees-row-checkbox js-record-select" type="checkbox"
            value="{{ $employee->trashed() ? $employee->public_uuid : $employee->doc_num }}"
            data-doc-num="{{ $employee->doc_num }}"
            data-public-uuid="{{ $employee->public_uuid }}"
            data-trashed="{{ $employee->trashed() ? 'true' : 'false' }}"
            aria-label="{{ __('hr.selected_records') }}" />
    </div>
@endif
