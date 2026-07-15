@php
    $isTrashed = $employee->trashed();
    $canRestore = $isTrashed && auth()->user()?->can('hr.employees.restore') && $employee->doc_num !== null;
    $canView = auth()->user()?->can('hr.employees.view') && $employee->doc_num !== null;
    $canClone = ! $isTrashed && auth()->user()?->can('hr.employees.clone') && $employee->doc_num !== null;
@endphp

@if ((! $isTrashed && ($canView || auth()->user()?->can('hr.employees.edit') || $canClone || auth()->user()?->can('hr.employees.delete'))) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($isTrashed)
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.hr.employees.show', $employee->doc_num) }}" data-doc-num="{{ $employee->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @if ($canRestore)
                    @if ($canView)
                        <div class="dropdown-divider"></div>
                    @endif
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $employee->doc_num }}" data-record-name="{{ $employee->full_name }}" data-hr-employees-restore-url="{{ route('admin.hr.employees.restore', $employee->doc_num) }}">
                        {{ __('hr.trash.restore') }}
                    </button>
                @endif
            @else
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.hr.employees.show', $employee->doc_num) }}" data-doc-num="{{ $employee->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @can('hr.employees.edit')
                    <a class="dropdown-item js-edit-record" href="{{ route('admin.hr.employees.edit', $employee->doc_num) }}" data-doc-num="{{ $employee->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endcan
                @if ($canClone)
                    <a class="dropdown-item js-clone-record" href="{{ route('admin.hr.employees.clone', $employee->doc_num) }}" data-doc-num="{{ $employee->doc_num }}">
                        {{ __('common.actions.clone_record') }}
                    </a>
                @endif
                @can('hr.employees.delete')
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $employee->doc_num }}" data-record-name="{{ $employee->full_name }}" data-hr-employees-delete-url="{{ route('admin.hr.employees.destroy', $employee->doc_num) }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endcan
            @endif
        </div>
    </div>
@endif
