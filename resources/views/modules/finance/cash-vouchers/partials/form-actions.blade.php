@php
    $isView = $mode === 'view';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $isTrashed = $record?->trashed() ?? false;
    $canList = auth()->user()?->can($resource.'.view');
    $canView = auth()->user()?->can($resource.'.view');
    $canEditPermission = auth()->user()?->can($resource.'.edit');
    $canClonePermission = auth()->user()?->can($resource.'.clone');
    $canEdit = $isView && ! $isTrashed && ! ($record?->isLockedForEditing() ?? true) && $canEditPermission;
    $canClone = $isView && ! $isTrashed && $canClonePermission;
    $canDelete = $isView && ! $isTrashed && ($record?->isDeletable() ?? false) && auth()->user()?->can($resource.'.delete');
    $canRestore = $isView && $isTrashed && ($record?->isDeletable() ?? false) && auth()->user()?->can($resource.'.restore');
    $canApprove = $isView && ! $isTrashed && ($record?->isDraft() ?? false) && auth()->user()?->can($resource.'.approve');
    $canCancel = $isView && ! $isTrashed && ($record?->isApproved() ?? false) && auth()->user()?->can($resource.'.cancel');
    $canSave = ! $isView;
    $shortcutTitles = [
        'back' => __('common.shortcuts.back'),
        'clone' => __('common.shortcuts.clone'),
        'delete' => __('common.shortcuts.delete'),
        'edit' => __('common.shortcuts.edit'),
        'save' => __('common.shortcuts.save'),
        'save_back' => __('common.shortcuts.save_back'),
        'save_clone' => __('common.shortcuts.save_clone'),
        'save_edit' => __('common.shortcuts.save_edit'),
        'save_view' => __('common.shortcuts.save_view'),
    ];
    $showSaveDropdown = ! $isCreateLike || $canView || $canEditPermission || $canList || $canClonePermission;
@endphp

<div class="d-flex flex-wrap justify-content-end gap-2 {{ $class ?? '' }}">
    @if ($canList)
        <a class="btn btn-falcon-default btn-sm" href="{{ route($routePrefix.'.index') }}" data-shortcut-action="form.back" title="{{ $shortcutTitles['back'] }}" data-bs-title="{{ $shortcutTitles['back'] }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
    @endif

    @if ($isView && $record)
        @if ($canEdit)
            <a class="btn btn-primary btn-sm" href="{{ route($routePrefix.'.edit', $record->doc_num) }}" data-shortcut-action="form.edit" title="{{ $shortcutTitles['edit'] }}" data-bs-title="{{ $shortcutTitles['edit'] }}">
                <span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}
            </a>
        @endif
        @if ($canClone)
            <a class="btn btn-falcon-default btn-sm" href="{{ route($routePrefix.'.clone', $record->doc_num) }}" data-shortcut-action="form.clone" title="{{ $shortcutTitles['clone'] }}" data-bs-title="{{ $shortcutTitles['clone'] }}">
                <span class="fas fa-copy me-1"></span>{{ __('common.actions.clone_record') }}
            </a>
        @endif
        @if ($canApprove)
            <button type="button" class="btn btn-success btn-sm js-cash-voucher-approve" data-url="{{ route($routePrefix.'.approve', $record->doc_num) }}">
                <span class="fas fa-check me-1"></span>{{ __($translationKey.'.actions.approve') }}
            </button>
        @endif
        @if ($canCancel)
            <button type="button" class="btn btn-warning btn-sm js-cash-voucher-cancel" data-url="{{ route($routePrefix.'.cancel', $record->doc_num) }}">
                <span class="fas fa-ban me-1"></span>{{ __($translationKey.'.actions.cancel') }}
            </button>
        @endif
        @if ($canDelete)
            <button type="button" class="btn btn-falcon-default text-danger btn-sm js-delete-record" data-shortcut-action="form.delete" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route($routePrefix.'.destroy', $record->doc_num) }}" data-redirect-url="{{ route($routePrefix.'.index') }}" title="{{ $shortcutTitles['delete'] }}" data-bs-title="{{ $shortcutTitles['delete'] }}">
                <span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}
            </button>
        @endif
        @if ($canRestore)
            <button type="button" class="btn btn-falcon-default text-success btn-sm js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route($routePrefix.'.restore', $record->doc_num) }}">
                <span class="fas fa-undo me-1"></span>{{ __('common.actions.restore') }}
            </button>
        @endif
    @endif

    @if ($canSave)
        <div class="btn-group btn-group-sm">
            <button type="submit" class="btn btn-primary js-finance-submit-action" data-submit-action="save" data-shortcut-action="form.save" title="{{ $shortcutTitles['save'] }}" data-bs-title="{{ $shortcutTitles['save'] }}">
                <span class="fas fa-save me-1"></span>{{ $isCreateLike ? __('common.actions.save_and_new') : __('common.actions.save_data') }}
            </button>
            @if ($showSaveDropdown)
                <button class="btn btn-primary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden">{{ __('common.fields.actions') }}</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end py-2">
                    @if (! $isCreateLike)
                        <button class="dropdown-item js-finance-submit-action" type="submit" data-submit-action="save" data-shortcut-action="form.save" title="{{ $shortcutTitles['save'] }}" data-bs-title="{{ $shortcutTitles['save'] }}">{{ __('common.actions.save') }}</button>
                    @endif
                    @if ($canView)
                        <button class="dropdown-item js-finance-submit-action" type="submit" data-submit-action="save_view" data-shortcut-action="form.save_view" title="{{ $shortcutTitles['save_view'] }}" data-bs-title="{{ $shortcutTitles['save_view'] }}">{{ __('common.actions.save_and_view') }}</button>
                    @endif
                    @if ($canEditPermission && $isCreateLike)
                        <button class="dropdown-item js-finance-submit-action" type="submit" data-submit-action="save_edit" data-shortcut-action="form.save_edit" title="{{ $shortcutTitles['save_edit'] }}" data-bs-title="{{ $shortcutTitles['save_edit'] }}">{{ __('common.actions.save_and_edit') }}</button>
                    @endif
                    @if ($canList)
                        <button class="dropdown-item js-finance-submit-action" type="submit" data-submit-action="save_back" data-shortcut-action="form.save_back" title="{{ $shortcutTitles['save_back'] }}" data-bs-title="{{ $shortcutTitles['save_back'] }}">{{ __('common.actions.save_and_back') }}</button>
                    @endif
                    @if ($canClonePermission)
                        <button class="dropdown-item js-finance-submit-action" type="submit" data-submit-action="save_clone" data-shortcut-action="form.save_clone" title="{{ $shortcutTitles['save_clone'] }}" data-bs-title="{{ $shortcutTitles['save_clone'] }}">{{ __('common.actions.save_and_clone') }}</button>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
