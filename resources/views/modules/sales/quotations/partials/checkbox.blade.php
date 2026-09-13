@if (($record->canDeleteDraft() && auth()->user()?->can('quotations.delete')) || ($record->trashed() && auth()->user()?->can('quotations.restore')))
    <div class="form-check mb-0 d-flex justify-content-center">
        <x-forms.input class="form-check-input js-record-select js-quotation-row-checkbox" type="checkbox" value="{{ $record->doc_num }}" data-doc-num="{{ $record->doc_num }}" aria-label="{{ $record->doc_num }}" />
    </div>
@endif
