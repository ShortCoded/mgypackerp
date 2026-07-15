@if (! $record->trashed())
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <input class="form-check-input js-currency-row-checkbox js-record-select" type="checkbox" value="{{ $record->doc_num }}" data-doc-num="{{ $record->doc_num }}" aria-label="{{ __('currencies.selected_records') }}">
    </div>
@endif
