@php
    $isTrashed = $record->trashed();
    $canView = auth()->user()?->can($resource.'.view') && $record->doc_num !== null;
    $canClone = ! $isTrashed && auth()->user()?->can($resource.'.clone') && $record->doc_num !== null;
    $isLocked = method_exists($record, 'isLockedForEditing') && $record->isLockedForEditing();
    $canEdit = ! $isTrashed && ! $isLocked && auth()->user()?->can($resource.'.edit') && $record->doc_num !== null;
    $canDelete = ! $isTrashed && ! $isLocked && auth()->user()?->can($resource.'.delete') && $record->doc_num !== null;
    $canRestore = $isTrashed && auth()->user()?->can($resource.'.restore') && $record->doc_num !== null;
@endphp

@if ((! $isTrashed && ($canView || $canEdit || $canClone || $canDelete)) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($isTrashed)
                @if ($canView)
                    <a class="dropdown-item" href="{{ route($routePrefix.'.show', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @if ($canRestore)
                    @if ($canView)
                        <div class="dropdown-divider"></div>
                    @endif
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route($routePrefix.'.restore', $record->doc_num) }}">
                        {{ __('common.actions.restore') }}
                    </button>
                @endif
            @else
                @if ($canView)
                    <a class="dropdown-item" href="{{ route($routePrefix.'.show', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @if ($canEdit)
                    <a class="dropdown-item js-edit-record" href="{{ route($routePrefix.'.edit', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endif
                @if ($canClone)
                    <a class="dropdown-item js-clone-record" href="{{ route($routePrefix.'.clone', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                        {{ __('common.actions.clone_record') }}
                    </a>
                @endif
                @if ($canDelete)
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route($routePrefix.'.destroy', $record->doc_num) }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endif
            @endif
        </div>
    </div>
@endif
