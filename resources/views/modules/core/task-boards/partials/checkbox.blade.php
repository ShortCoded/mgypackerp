@php
    $isTrashed = $board->trashed();
    $canSelect = $board->doc_num !== null
        && (
            ($isTrashed && auth()->user()?->can('task_boards.restore'))
            || (! $isTrashed && (
                auth()->user()?->can('task_boards.delete')
                || auth()->user()?->can('task_boards.bulk_activate')
                || auth()->user()?->can('task_boards.bulk_deactivate')
            ))
        );
@endphp

@if ($canSelect)
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <x-forms.input class="form-check-input js-task-board-row-checkbox js-record-select" type="checkbox" value="{{ $board->doc_num }}" data-doc-num="{{ $board->doc_num }}" aria-label="{{ __('task_boards.select_record', ['record' => $board->doc_num]) }}" />
    </div>
@endif
