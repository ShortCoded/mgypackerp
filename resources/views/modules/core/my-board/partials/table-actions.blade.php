@php
    $isTrashed = $task->trashed();
    $canChangeStatus = ! $isTrashed
        && $type === \Modules\Core\Models\UserTask::TypeTask
        && ($canMove ?? false);
    $hasPrimaryActions = $isTrashed
        ? (($canView ?? false) || ($canRestore ?? false))
        : (($canView ?? false) || ($canEdit ?? false) || $canChangeStatus || ($canClone ?? false) || ($canDelete ?? false));
@endphp

@if ($hasPrimaryActions)
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($canView ?? false)
                <a class="dropdown-item" href="{{ $routes['show'] }}" data-doc-num="{{ $task->doc_num }}">
                    {{ __('common.actions.view') }}
                </a>
            @endif

            @if ($isTrashed)
                @if ($canRestore ?? false)
                    @if ($canView ?? false)
                        <div class="dropdown-divider"></div>
                    @endif
                    <button type="button" class="dropdown-item text-success js-my-board-table-restore"
                        data-doc-num="{{ $task->doc_num }}"
                        data-record-name="{{ $task->title }}"
                        data-restore-url="{{ $routes['restore'] }}">
                        {{ __('common.actions.restore') }}
                    </button>
                @endif
            @else
                @if ($canEdit ?? false)
                    <a class="dropdown-item js-edit-record" href="{{ $routes['edit'] }}" data-doc-num="{{ $task->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endif

                @if ($canChangeStatus)
                    <button class="dropdown-item js-my-board-table-status-open" type="button"
                        data-url="{{ $routes['status'] }}"
                        data-type="{{ $type }}"
                        data-current-status="{{ $task->status }}"
                        data-current-label="{{ __("user_tasks.statuses.{$task->status}") }}">
                        {{ __('user_tasks.actions.change_status') }}
                    </button>
                @endif

                @if ($canClone ?? false)
                    <button type="button" class="dropdown-item js-my-board-table-clone"
                        data-doc-num="{{ $task->doc_num }}"
                        data-record-name="{{ $task->title }}"
                        data-clone-url="{{ $routes['clone'] }}">
                        {{ __('user_tasks.actions.duplicate_record') }}
                    </button>
                @endif

                @if ($canDelete ?? false)
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger js-delete-record js-my-board-table-delete" data-doc-num="{{ $task->doc_num }}" data-record-name="{{ $task->title }}" data-delete-url="{{ $routes['destroy'] }}" data-my-board-delete-url="{{ $routes['destroy'] }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endif
            @endif
        </div>
    </div>
@endif
