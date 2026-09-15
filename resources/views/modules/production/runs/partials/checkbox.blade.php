@if (auth()->user()?->can('production.runs.delete') && ! $record->trashed() && $canChange)
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <x-forms.input
            class="form-check-input js-record-select"
            type="checkbox"
            :value="$record->public_id"
            :data-doc-num="$record->run_number"
            :aria-label="__('production_execution.messages.select_run', ['document' => $record->run_number])"
        />
    </div>
@endif
