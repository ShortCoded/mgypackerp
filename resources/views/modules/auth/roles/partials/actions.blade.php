@php
    $isTrashed = $role->trashed();
    $isProtectedRole = $isProtectedRole ?? false;
    $canRestore = $isTrashed
        && auth()->user()?->can('roles.restore')
        && $role->doc_num !== null;
    $canView = auth()->user()?->can('roles.view')
        && $role->doc_num !== null;
    $canClone = ! $isTrashed
        && ! $isProtectedRole
        && auth()->user()?->can('roles.clone')
        && $role->doc_num !== null;
    $canDelete = ! $isTrashed
        && ! $isProtectedRole
        && auth()->user()?->can('roles.delete');
@endphp

@if ((! $isTrashed && ($canView || auth()->user()?->can('roles.edit') || $canClone || $canDelete)) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($isTrashed)
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.roles.show', $role->doc_num) }}" data-doc-num="{{ $role->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @if ($canRestore)
                    @if ($canView)
                        <div class="dropdown-divider"></div>
                    @endif
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $role->doc_num }}" data-record-name="{{ $role->name }}" data-restore-url="{{ route('admin.roles.restore', $role->doc_num) }}" data-role-restore-url="{{ route('admin.roles.restore', $role->doc_num) }}">
                        {{ __('roles.trash.restore') }}
                    </button>
                @endif
            @else
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.roles.show', $role->doc_num) }}" data-doc-num="{{ $role->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @can('roles.edit')
                    <a class="dropdown-item js-edit-record" href="{{ route('admin.roles.edit', $role->doc_num) }}" data-doc-num="{{ $role->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endcan
                @if ($canClone)
                    <a class="dropdown-item js-clone-record" href="{{ route('admin.roles.clone', $role) }}" data-doc-num="{{ $role->doc_num }}">
                        {{ __('common.actions.clone_record') }}
                    </a>
                @endif
                @if ($canDelete)
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $role->doc_num }}" data-record-name="{{ $role->name }}" data-delete-url="{{ route('admin.roles.destroy', $role->doc_num) }}" data-role-delete-url="{{ route('admin.roles.destroy', $role->doc_num) }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endif
            @endif
        </div>
    </div>
@endif
