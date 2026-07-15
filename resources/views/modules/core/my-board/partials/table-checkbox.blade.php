@php
    $isTrashed = $task->trashed();
    $canSelect = $task->doc_num !== null
        && ($isTrashed
            ? auth()->user()?->can('my_board.restore')
            : (auth()->user()?->can('my_board.delete') || auth()->user()?->can('my_board.edit')));
@endphp

@if ($canSelect)
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <input class="form-check-input js-my-board-table-row-checkbox js-record-select" type="checkbox" value="{{ $task->doc_num }}" data-doc-num="{{ $task->doc_num }}" aria-label="{{ __('user_tasks.select_record', ['record' => $task->doc_num]) }}">
    </div>
@endif
