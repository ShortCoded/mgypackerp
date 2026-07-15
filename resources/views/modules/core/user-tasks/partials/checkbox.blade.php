@can('tasks.bulk_delete')
    @if (! $task->trashed())
        <div class="form-check mb-0">
            <input class="form-check-input js-record-checkbox" type="checkbox" value="{{ $task->doc_num }}" data-doc-num="{{ $task->doc_num }}" aria-label="{{ __('user_tasks.select_record', ['record' => $task->doc_num]) }}">
        </div>
    @endif
@endcan
