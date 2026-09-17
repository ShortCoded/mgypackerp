@php($disabled = $record->trashed() || ! $canChange || ! auth()->user()?->can('production.expenses.delete'))
<div class="form-check mb-0 d-flex justify-content-center">
    <x-forms.input class="form-check-input js-record-select" type="checkbox" value="{{ $record->doc_num }}" data-doc-num="{{ $record->doc_num }}" :disabled="$disabled" />
</div>
