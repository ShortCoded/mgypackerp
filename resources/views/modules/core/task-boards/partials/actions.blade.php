@php
    $isTrashed = $board->trashed();
    $canView = auth()->user()?->can('task_boards.view') && $board->doc_num !== null;
    $canEdit = ! $isTrashed && auth()->user()?->can('task_boards.update') && $board->doc_num !== null;
    $canDelete = ! $isTrashed && auth()->user()?->can('task_boards.delete') && $board->doc_num !== null;
    $canRestore = $isTrashed && auth()->user()?->can('task_boards.restore') && $board->doc_num !== null;
    $canDisplay = ! $isTrashed && auth()->user()?->can('task_boards.display') && $board->doc_num !== null;
    $canRegenerate = ! $isTrashed && auth()->user()?->can('task_boards.regenerate_public_url') && $board->doc_num !== null;
@endphp

@if (($isTrashed && ($canView || $canRestore)) || (! $isTrashed && ($canView || $canEdit || $canDisplay || $canRegenerate || $canDelete)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($isTrashed)
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.task-boards.show', $board->doc_num) }}" data-doc-num="{{ $board->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif

                @if ($canRestore)
                    @if ($canView)
                        <div class="dropdown-divider"></div>
                    @endif
                    <button type="button" class="dropdown-item text-success js-restore-task-board" data-doc-num="{{ $board->doc_num }}" data-url="{{ route('admin.task-boards.restore', $board->doc_num) }}">
                        {{ __('task_boards.actions.restore') }}
                    </button>
                @endif
            @else
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.task-boards.show', $board->doc_num) }}" data-doc-num="{{ $board->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif

                @if ($canEdit)
                    <a class="dropdown-item js-edit-record" href="{{ route('admin.task-boards.edit', $board->doc_num) }}" data-doc-num="{{ $board->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endif

                @if ($canDisplay || $canRegenerate)
                    @if ($canView || $canEdit)
                        <div class="dropdown-divider"></div>
                    @endif

                    @if ($canDisplay)
                        <button class="dropdown-item js-copy-task-board-url" type="button" data-task-board-public-link="display" data-display-url="{{ $board->displayUrl() }}">
                            {{ __('task_boards.actions.copy_url') }}
                        </button>
                        <a class="dropdown-item" href="{{ $board->displayUrl() }}" target="_blank" rel="noopener" data-task-board-public-link="display">
                            {{ __('task_boards.actions.open_display') }}
                        </a>
                        <button class="dropdown-item js-copy-task-board-url" type="button" data-task-board-public-link="user-display" data-display-url="{{ $board->userDisplayUrl() }}">
                            {{ __('task_boards.actions.copy_user_display_url') }}
                        </button>
                        <a class="dropdown-item" href="{{ $board->userDisplayUrl() }}" target="_blank" rel="noopener" data-task-board-public-link="user-display">
                            {{ __('task_boards.actions.user_display') }}
                        </a>
                    @endif

                    @if ($canRegenerate)
                        <button class="dropdown-item js-regenerate-task-board-url" type="button" data-url="{{ route('admin.task-boards.regenerate-public-url', $board->doc_num) }}">
                            {{ __('task_boards.actions.regenerate_url') }}
                        </button>
                    @endif
                @endif

                @if ($canDelete)
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger js-delete-task-board" data-doc-num="{{ $board->doc_num }}" data-url="{{ route('admin.task-boards.destroy', $board->doc_num) }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endif
            @endif
        </div>
    </div>
@endif
