@php
    $canSelect = $task->trashed()
        ? auth()->user()?->can('quick_tasks.restore')
        : auth()->user()?->can('quick_tasks.delete');
@endphp

@if ($canSelect)
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <input class="form-check-input js-record-select js-record-checkbox" type="checkbox" value="{{ $task->doc_num }}" data-doc-num="{{ $task->doc_num }}" aria-label="{{ __('quick_tasks.select_record', ['record' => $task->doc_num]) }}">
    </div>
@endif
