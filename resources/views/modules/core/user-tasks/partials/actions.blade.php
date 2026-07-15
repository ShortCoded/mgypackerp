<div class="btn-group btn-group-sm" role="group">
    @can('tasks.view')
        <a class="btn btn-falcon-default" href="{{ route('admin.tasks.show', $task->doc_num) }}" title="{{ __('common.actions.view') }}">
            <span class="fas fa-eye"></span>
        </a>
    @endcan
    @if (! $task->trashed())
        @can('tasks.edit')
            <a class="btn btn-falcon-default js-edit-record" href="{{ route('admin.tasks.edit', $task->doc_num) }}" title="{{ __('common.actions.edit') }}">
                <span class="fas fa-edit"></span>
            </a>
        @endcan
        @can('tasks.clone')
            <a class="btn btn-falcon-default" href="{{ route('admin.tasks.clone', $task->doc_num) }}" title="{{ __('common.actions.clone') }}">
                <span class="fas fa-copy"></span>
            </a>
        @endcan
        @can('tasks.delete')
            <button class="btn btn-falcon-danger js-delete-record" type="button" data-doc-num="{{ $task->doc_num }}" data-url="{{ route('admin.tasks.destroy', $task->doc_num) }}" title="{{ __('common.actions.delete') }}">
                <span class="fas fa-trash-alt"></span>
            </button>
        @endcan
    @else
        @can('tasks.restore')
            <button class="btn btn-falcon-success js-restore-record" type="button" data-doc-num="{{ $task->doc_num }}" data-url="{{ route('admin.tasks.restore', $task->doc_num) }}" title="{{ __('common.actions.restore') }}">
                <span class="fas fa-trash-restore"></span>
            </button>
        @endcan
    @endif
</div>
