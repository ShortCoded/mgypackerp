@if ($mode !== 'view')
    <button class="btn btn-primary" type="submit" data-submit-action="save" data-shortcut-action="form.save" title="{{ __('common.shortcuts.save') }}" data-bs-title="{{ __('common.shortcuts.save') }}">
        <span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}
    </button>
    @can('tasks.view')
        <button class="btn btn-falcon-primary" type="submit" data-submit-action="save_view" data-shortcut-action="form.save_view" title="{{ __('common.shortcuts.save_view') }}" data-bs-title="{{ __('common.shortcuts.save_view') }}">
            {{ __('common.actions.save_and_view') }}
        </button>
    @endcan
    @can('tasks.edit')
        <button class="btn btn-falcon-primary" type="submit" data-submit-action="save_edit" data-shortcut-action="form.save_edit" title="{{ __('common.shortcuts.save_edit') }}" data-bs-title="{{ __('common.shortcuts.save_edit') }}">
            {{ __('common.actions.save_and_edit') }}
        </button>
    @endcan
    @can('tasks.create')
        <button class="btn btn-falcon-primary" type="submit" data-submit-action="save_new" data-shortcut-action="form.save_new" title="{{ __('common.shortcuts.save_new') }}" data-bs-title="{{ __('common.shortcuts.save_new') }}">
            {{ __('common.actions.save_and_new') }}
        </button>
    @endcan
@else
    @can('tasks.edit')
        <a class="btn btn-primary" href="{{ route('admin.tasks.edit', $record->doc_num) }}" data-shortcut-action="form.edit" title="{{ __('common.shortcuts.edit') }}" data-bs-title="{{ __('common.shortcuts.edit') }}">
            <span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}
        </a>
    @endcan
@endif
