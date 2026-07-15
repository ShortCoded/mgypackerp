@php
    $isTrashed = $task->trashed();
    $statusActions = $statusActions ?? [];
    $canView = auth()->user()?->can('quick_tasks.view') && $task->doc_num;
    $canEdit = ! $isTrashed && auth()->user()?->can('quick_tasks.update') && $task->doc_num;
    $canDelete = ! $isTrashed && auth()->user()?->can('quick_tasks.delete') && $task->doc_num;
    $canRestore = $isTrashed && auth()->user()?->can('quick_tasks.restore') && $task->doc_num;
@endphp

@if (($isTrashed && ($canView || $canRestore)) || (! $isTrashed && ($canView || $canEdit || $canDelete || $statusActions !== [])))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($canView)
                <a class="dropdown-item" href="{{ route('admin.quick-tasks.show', $task->doc_num) }}" data-doc-num="{{ $task->doc_num }}">
                    {{ __('common.actions.view') }}
                </a>
            @endif

            @if ($isTrashed)
                @if ($canRestore)
                    @if ($canView)
                        <div class="dropdown-divider"></div>
                    @endif
                    <button type="button" class="dropdown-item text-success js-restore-record" data-url="{{ route('admin.quick-tasks.restore', $task->doc_num) }}" data-doc-num="{{ $task->doc_num }}">
                        {{ __('quick_tasks.actions.restore') }}
                    </button>
                @endif
            @else
                @if ($canEdit)
                    <a class="dropdown-item js-edit-record" href="{{ route('admin.quick-tasks.edit', $task->doc_num) }}" data-doc-num="{{ $task->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endif

                @if ($statusActions !== [])
                    <div class="dropdown-divider"></div>
                    @foreach ($statusActions as $action)
                        <button type="button" class="dropdown-item text-{{ $action['color'] }} js-quick-task-status-action" data-url="{{ route('admin.quick-tasks.change-status', $task->doc_num) }}" data-status="{{ $action['status'] }}" data-current-status="{{ $task->status }}">
                            <span class="fas {{ $action['icon'] }} me-2"></span>{{ $action['label'] }}
                        </button>
                    @endforeach
                @endif

                @if ($canDelete)
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-url="{{ route('admin.quick-tasks.destroy', $task->doc_num) }}" data-doc-num="{{ $task->doc_num }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endif
            @endif
        </div>
    </div>
@endif
