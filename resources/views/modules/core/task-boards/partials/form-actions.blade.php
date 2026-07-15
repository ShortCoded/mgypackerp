@php
    $record = $board ?? $record ?? null;
    $isView = $mode === 'view';
    $isCreate = $mode === 'create';
    $isTrashed = (bool) ($record?->trashed() ?? false);
    $canList = auth()->user()?->can('task_boards.view');
    $canSave = ! $isView;
    $canView = auth()->user()?->can('task_boards.view');
    $canEdit = auth()->user()?->can('task_boards.update');
    $canCreate = auth()->user()?->can('task_boards.create');
    $canDelete = auth()->user()?->can('task_boards.delete');
    $canRestore = auth()->user()?->can('task_boards.restore');
@endphp

<div class="d-flex flex-wrap justify-content-end gap-2 {{ $class ?? '' }}">
    @if ($canList)
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.task-boards.index') }}" data-shortcut-action="form.back" title="{{ __('common.shortcuts.back') }}" data-bs-title="{{ __('common.shortcuts.back') }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
    @endif

    @if ($record && ! $isTrashed && auth()->user()?->can('task_boards.display'))
        <button class="btn btn-falcon-default btn-sm js-copy-task-board-url" type="button" data-task-board-public-link="display" data-display-url="{{ $record->displayUrl() }}">
            <span class="fas fa-copy me-1"></span>{{ __('task_boards.actions.copy_url') }}
        </button>
        <a class="btn btn-falcon-info btn-sm" href="{{ $record->displayUrl() }}" target="_blank" rel="noopener" data-task-board-public-link="display">
            <span class="fas fa-external-link-alt me-1"></span>{{ __('task_boards.actions.open_display') }}
        </a>
        <button class="btn btn-falcon-default btn-sm js-copy-task-board-url" type="button" data-task-board-public-link="user-display" data-display-url="{{ $record->userDisplayUrl() }}">
            <span class="fas fa-copy me-1"></span>{{ __('task_boards.actions.copy_user_display_url') }}
        </button>
        <a class="btn btn-falcon-info btn-sm" href="{{ $record->userDisplayUrl() }}" target="_blank" rel="noopener" data-task-board-public-link="user-display">
            <span class="fas fa-user-friends me-1"></span>{{ __('task_boards.actions.user_display') }}
        </a>
    @endif

    @if ($isView && $record)
        @if ($isTrashed && $canRestore)
            <button class="btn btn-falcon-success btn-sm js-restore-task-board" type="button" data-url="{{ route('admin.task-boards.restore', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}" data-redirect-url="{{ route('admin.task-boards.show', $record->doc_num) }}">
                <span class="fas fa-undo me-1"></span>{{ __('task_boards.actions.restore') }}
            </button>
        @endif

        @if (! $isTrashed && $canEdit)
            <a class="btn btn-primary btn-sm" href="{{ route('admin.task-boards.edit', $record->doc_num) }}" data-shortcut-action="form.edit" title="{{ __('common.shortcuts.edit') }}" data-bs-title="{{ __('common.shortcuts.edit') }}">
                <span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}
            </a>
        @endif

        @if (! $isTrashed && auth()->user()?->can('task_boards.regenerate_public_url'))
            <button class="btn btn-falcon-warning btn-sm js-regenerate-task-board-url" type="button" data-url="{{ route('admin.task-boards.regenerate-public-url', $record->doc_num) }}">
                <span class="fas fa-redo me-1"></span>{{ __('task_boards.actions.regenerate_url') }}
            </button>
        @endif

        @if (! $isTrashed && $canDelete)
            <button class="btn btn-falcon-default text-danger btn-sm js-delete-task-board" type="button" data-url="{{ route('admin.task-boards.destroy', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}" data-redirect-url="{{ route('admin.task-boards.index') }}" data-shortcut-action="form.delete" title="{{ __('common.shortcuts.delete') }}" data-bs-title="{{ __('common.shortcuts.delete') }}">
                <span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}
            </button>
        @endif
    @endif

    @if ($canSave)
        <div class="btn-group btn-group-sm">
            <button class="btn btn-primary" type="submit" data-submit-action="save" data-shortcut-action="form.save" title="{{ __('common.shortcuts.save') }}" data-bs-title="{{ __('common.shortcuts.save') }}">
                <span class="fas fa-save me-1"></span>{{ __('common.actions.save_data') }}
            </button>
            <button class="btn btn-primary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="visually-hidden">{{ __('common.fields.actions') }}</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end py-2">
                @if ($canView)
                    <button class="dropdown-item" type="submit" data-submit-action="save_view" data-shortcut-action="form.save_view" title="{{ __('common.shortcuts.save_view') }}" data-bs-title="{{ __('common.shortcuts.save_view') }}">{{ __('common.actions.save_and_view') }}</button>
                @endif
                @if ($canEdit && $isCreate)
                    <button class="dropdown-item" type="submit" data-submit-action="save_edit" data-shortcut-action="form.save_edit" title="{{ __('common.shortcuts.save_edit') }}" data-bs-title="{{ __('common.shortcuts.save_edit') }}">{{ __('common.actions.save_and_edit') }}</button>
                @endif
                @if ($canList)
                    <button class="dropdown-item" type="submit" data-submit-action="save_back" data-shortcut-action="form.save_back" title="{{ __('common.shortcuts.save_back') }}" data-bs-title="{{ __('common.shortcuts.save_back') }}">{{ __('common.actions.save_and_back') }}</button>
                @endif
                @if ($canCreate && $isCreate)
                    <button class="dropdown-item" type="submit" data-submit-action="save_new" data-shortcut-action="form.save_new" title="{{ __('common.shortcuts.save_new') }}" data-bs-title="{{ __('common.shortcuts.save_new') }}">{{ __('common.actions.save_and_new') }}</button>
                @endif
            </div>
        </div>
    @endif
</div>
