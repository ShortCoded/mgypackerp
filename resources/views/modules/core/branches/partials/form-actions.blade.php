@php
    $canList = auth()->user()?->can('branches.view');
    $canSave = ! $isView;
    $canView = auth()->user()?->can('branches.view');
    $canEdit = auth()->user()?->can('branches.edit');
    $canCreate = auth()->user()?->can('branches.create');
    $canClone = auth()->user()?->can('branches.clone');
    $isTrashed = $record?->trashed() ?? false;
    $canRestore = $isView && $isTrashed && auth()->user()?->can('branches.restore') && $record?->doc_num !== null;
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
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
    $mainSubmitAction = 'save';
    $mainShortcutTitle = $shortcutTitles['save'];
    $showSaveDropdown = ! $isCreateLike || $canView || $canEdit || $canList || $canClone;
@endphp

<div class="d-flex flex-wrap justify-content-end gap-2 {{ $class ?? '' }}">
    @if ($canList)
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.branches.index') }}" data-shortcut-action="form.back" title="{{ $shortcutTitles['back'] }}" data-bs-title="{{ $shortcutTitles['back'] }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
    @endif

    @if ($isView && $record)
        @if (! $isTrashed && $canEdit)
            <a class="btn btn-primary btn-sm" href="{{ route('admin.branches.edit', $record->doc_num) }}" data-shortcut-action="form.edit" title="{{ $shortcutTitles['edit'] }}" data-bs-title="{{ $shortcutTitles['edit'] }}">
                <span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}
            </a>
        @endif

        @if (! $isTrashed && $canClone)
            <a class="btn btn-falcon-default btn-sm js-clone-record" href="{{ route('admin.branches.clone', $record->doc_num) }}" data-shortcut-action="form.clone" title="{{ $shortcutTitles['clone'] }}" data-bs-title="{{ $shortcutTitles['clone'] }}">
                <span class="fas fa-copy me-1"></span>{{ __('common.actions.clone_record') }}
            </a>
        @endif

        @if ($canRestore)
            <button type="button" class="btn btn-falcon-default text-success btn-sm js-restore-record" data-doc-num="{{ $record->doc_num }}" data-record-name="{{ $record->name }}" data-restore-url="{{ route('admin.branches.restore', $record->doc_num) }}" data-branch-restore-url="{{ route('admin.branches.restore', $record->doc_num) }}">
                <span class="fas fa-undo me-1"></span>{{ __('branches.trash.restore') }}
            </button>
        @endif

        @if (! $isTrashed && auth()->user()?->can('branches.delete'))
            <button type="button" class="btn btn-falcon-default text-danger btn-sm js-delete-record" data-shortcut-action="form.delete" data-doc-num="{{ $record->doc_num }}" data-url="{{ route('admin.branches.destroy', $record->doc_num) }}" data-delete-url="{{ route('admin.branches.destroy', $record->doc_num) }}" data-branch-delete-url="{{ route('admin.branches.destroy', $record->doc_num) }}" data-redirect-url="{{ route('admin.branches.index') }}" title="{{ $shortcutTitles['delete'] }}" data-bs-title="{{ $shortcutTitles['delete'] }}">
                <span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}
            </button>
        @endif
    @endif

    @if ($canSave)
        <div class="btn-group btn-group-sm">
            <button type="submit" class="btn btn-primary js-branch-submit-action" data-submit-action="{{ $mainSubmitAction }}" data-shortcut-action="form.save" title="{{ $mainShortcutTitle }}" data-bs-title="{{ $mainShortcutTitle }}">
                <span class="fas fa-save me-1"></span>{{ __('common.actions.save_data') }}
            </button>
            @if ($showSaveDropdown)
                <button class="btn btn-primary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden">{{ __('common.fields.actions') }}</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end py-2">
                    @if (! $isCreateLike)
                        <button class="dropdown-item js-branch-submit-action" type="submit" data-submit-action="save" data-shortcut-action="form.save" title="{{ $shortcutTitles['save'] }}" data-bs-title="{{ $shortcutTitles['save'] }}">{{ __('common.actions.save') }}</button>
                    @endif
                    @if ($canView)
                        <button class="dropdown-item js-branch-submit-action" type="submit" data-submit-action="save_view" data-shortcut-action="form.save_view" title="{{ $shortcutTitles['save_view'] }}" data-bs-title="{{ $shortcutTitles['save_view'] }}">{{ __('common.actions.save_and_view') }}</button>
                    @endif
                    @if ($canEdit && $isCreateLike)
                        <button class="dropdown-item js-branch-submit-action" type="submit" data-submit-action="save_edit" data-shortcut-action="form.save_edit" title="{{ $shortcutTitles['save_edit'] }}" data-bs-title="{{ $shortcutTitles['save_edit'] }}">{{ __('common.actions.save_and_edit') }}</button>
                    @endif
                    @if ($canList)
                        <button class="dropdown-item js-branch-submit-action" type="submit" data-submit-action="save_back" data-shortcut-action="form.save_back" title="{{ $shortcutTitles['save_back'] }}" data-bs-title="{{ $shortcutTitles['save_back'] }}">{{ __('common.actions.save_and_back') }}</button>
                    @endif
                    @if ($canClone)
                        <button class="dropdown-item js-branch-submit-action" type="submit" data-submit-action="save_clone" data-shortcut-action="form.save_clone" title="{{ $shortcutTitles['save_clone'] }}" data-bs-title="{{ $shortcutTitles['save_clone'] }}">{{ __('common.actions.save_and_clone') }}</button>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
