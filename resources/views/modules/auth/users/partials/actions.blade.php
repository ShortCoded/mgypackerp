@php
    $isTrashed = $user->trashed();
    $canRestore = $isTrashed
        && auth()->user()?->can('users.restore')
        && $user->doc_num !== null;
    $canView = auth()->user()?->can('users.view')
        && $user->doc_num !== null;
    $canClone = ! $isTrashed
        && auth()->user()?->can('users.clone')
        && $user->doc_num !== null;
@endphp

@if ((! $isTrashed && ($canView || auth()->user()?->can('users.edit') || $canClone || auth()->user()?->can('users.delete'))) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($isTrashed)
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.users.show', $user->doc_num) }}" data-doc-num="{{ $user->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @if ($canRestore)
                    @if ($canView)
                        <div class="dropdown-divider"></div>
                    @endif
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $user->doc_num }}" data-record-name="{{ $user->name }}" data-restore-url="{{ route('admin.users.restore', $user->doc_num) }}" data-user-restore-url="{{ route('admin.users.restore', $user->doc_num) }}">
                        {{ __('users.trash.restore') }}
                    </button>
                @endif
            @else
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.users.show', $user->doc_num) }}" data-doc-num="{{ $user->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @can('users.edit')
                    <a class="dropdown-item js-edit-record" href="{{ route('admin.users.edit', $user->doc_num) }}" data-doc-num="{{ $user->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endcan
                @if ($canClone)
                    <a class="dropdown-item js-clone-record" href="{{ route('admin.users.clone', $user->doc_num) }}" data-doc-num="{{ $user->doc_num }}">
                        {{ __('common.actions.clone_record') }}
                    </a>
                @endif
                @can('users.delete')
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $user->doc_num }}" data-record-name="{{ $user->name }}" data-delete-url="{{ route('admin.users.destroy', $user->doc_num) }}" data-user-delete-url="{{ route('admin.users.destroy', $user->doc_num) }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endcan
            @endif
        </div>
    </div>
@endif
