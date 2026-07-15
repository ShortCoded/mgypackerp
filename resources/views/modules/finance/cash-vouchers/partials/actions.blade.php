@php
    $isTrashed = $record->trashed();
    $canView = auth()->user()?->can($resource.'.view') && $record->doc_num !== null;
    $canClone = ! $isTrashed && auth()->user()?->can($resource.'.clone') && $record->doc_num !== null;
    $canEdit = ! $isTrashed && ! $record->isLockedForEditing() && auth()->user()?->can($resource.'.edit') && $record->doc_num !== null;
    $canDelete = ! $isTrashed && $record->isDeletable() && auth()->user()?->can($resource.'.delete') && $record->doc_num !== null;
    $canRestore = $isTrashed && $record->isDeletable() && auth()->user()?->can($resource.'.restore') && $record->doc_num !== null;
    $canApprove = ! $isTrashed && $record->isDraft() && auth()->user()?->can($resource.'.approve') && $record->doc_num !== null;
    $canCancel = ! $isTrashed && $record->isApproved() && auth()->user()?->can($resource.'.cancel') && $record->doc_num !== null;
@endphp

@if ((! $isTrashed && ($canView || $canEdit || $canClone || $canApprove || $canCancel || $canDelete)) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($canView)
                <a class="dropdown-item" href="{{ route($routePrefix.'.show', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                    {{ __('common.actions.view') }}
                </a>
            @endif

            @if ($isTrashed)
                @if ($canRestore)
                    @if ($canView)
                        <div class="dropdown-divider"></div>
                    @endif
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route($routePrefix.'.restore', $record->doc_num) }}">
                        {{ __('common.actions.restore') }}
                    </button>
                @endif
            @else
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
                @if ($canApprove || $canCancel || $canDelete)
                    <div class="dropdown-divider"></div>
                @endif
                @if ($canApprove)
                    <button type="button" class="dropdown-item text-success js-cash-voucher-approve" data-doc-num="{{ $record->doc_num }}" data-url="{{ route($routePrefix.'.approve', $record->doc_num) }}">
                        {{ __($translationKey.'.actions.approve') }}
                    </button>
                @endif
                @if ($canCancel)
                    <button type="button" class="dropdown-item text-warning js-cash-voucher-cancel" data-doc-num="{{ $record->doc_num }}" data-url="{{ route($routePrefix.'.cancel', $record->doc_num) }}">
                        {{ __($translationKey.'.actions.cancel') }}
                    </button>
                @endif
                @if ($canDelete)
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route($routePrefix.'.destroy', $record->doc_num) }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endif
            @endif
        </div>
    </div>
@endif
