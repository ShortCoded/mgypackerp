@php
    $record = $task ?? $record ?? null;
    $isView = $mode === 'view';
    $isCreate = $mode === 'create';
    $isTrashed = $record?->trashed() ?? false;
    $canList = auth()->user()?->can('quick_tasks.view');
    $canSave = ! $isView && ! $isTrashed;
    $canView = auth()->user()?->can('quick_tasks.view');
    $canEdit = auth()->user()?->can('quick_tasks.update');
    $canCreate = auth()->user()?->can('quick_tasks.create');
    $canDelete = auth()->user()?->can('quick_tasks.delete');
    $canRestore = $isView && $isTrashed && auth()->user()?->can('quick_tasks.restore') && $record?->doc_num;
@endphp

<div class="d-flex flex-wrap justify-content-end gap-2 {{ $class ?? '' }}">
    @if ($canList)
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.quick-tasks.index') }}" data-shortcut-action="form.back" title="{{ __('common.shortcuts.back') }}" data-bs-title="{{ __('common.shortcuts.back') }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
    @endif

    @can('task_boards.view')
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.task-boards.index') }}">
            <span class="fas fa-columns me-1"></span>{{ __('task_boards.title') }}
        </a>
    @endcan

    @if ($isView && $record)
        @if (! $isTrashed && $canEdit)
            <a class="btn btn-primary btn-sm" href="{{ route('admin.quick-tasks.edit', $record->doc_num) }}" data-shortcut-action="form.edit" title="{{ __('common.shortcuts.edit') }}" data-bs-title="{{ __('common.shortcuts.edit') }}">
                <span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}
            </a>
        @endif

        @if ($canRestore)
            <button class="btn btn-falcon-default text-success btn-sm js-restore-record" type="button" data-url="{{ route('admin.quick-tasks.restore', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                <span class="fas fa-undo me-1"></span>{{ __('common.actions.restore') }}
            </button>
        @endif

        @if (! $isTrashed && $canDelete)
            <button class="btn btn-falcon-default text-danger btn-sm js-delete-record" type="button" data-url="{{ route('admin.quick-tasks.destroy', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}" data-redirect-url="{{ route('admin.quick-tasks.index') }}" data-shortcut-action="form.delete" title="{{ __('common.shortcuts.delete') }}" data-bs-title="{{ __('common.shortcuts.delete') }}">
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
