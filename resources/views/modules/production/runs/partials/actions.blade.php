@php
    $isTrashed = $record->trashed();
    $canView = ! $isTrashed && auth()->user()?->can('production.runs.view');
    $canClone = ! $isTrashed && auth()->user()?->can('production.runs.clone');
    $canEdit = ! $isTrashed && $canChange && auth()->user()?->can('production.runs.edit');
    $canDelete = ! $isTrashed && $canChange && auth()->user()?->can('production.runs.delete');
    $canRestore = $isTrashed && auth()->user()?->can('production.runs.restore');
@endphp

@if ($canView || $canClone || $canEdit || $canDelete || $canRestore)
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($canView)
                <a class="dropdown-item" href="{{ route('admin.production.runs.show', $record) }}">{{ __('common.actions.view') }}</a>
            @endif
            @if ($canEdit)
                <a class="dropdown-item" href="{{ route('admin.production.runs.edit', $record) }}">{{ __('common.actions.edit') }}</a>
            @endif
            @if ($canClone)
                <a class="dropdown-item" href="{{ route('admin.production.runs.clone', $record) }}">{{ __('common.actions.clone_record') }}</a>
            @endif
            @if ($canDelete)
                <div class="dropdown-divider"></div>
                <button class="dropdown-item text-danger" type="button" data-action="delete" data-confirm="{{ __('production_execution.messages.confirm_delete_run') }}" data-url="{{ route('admin.production.runs.destroy', $record) }}">{{ __('common.actions.delete') }}</button>
            @endif
            @if ($canRestore)
                <button class="dropdown-item text-success" type="button" data-action="restore" data-url="{{ route('admin.production.runs.restore', $record->public_id) }}">{{ __('common.actions.restore') }}</button>
            @endif
        </div>
    </div>
@endif
