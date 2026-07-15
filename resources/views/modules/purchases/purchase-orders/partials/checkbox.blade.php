@if(! $record->trashed() && $record->isDeletable() && auth()->user()?->can('purchase_orders.delete'))
    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
        <input class="form-check-input js-record-select" type="checkbox" value="{{ $record->doc_num }}" aria-label="{{ __('purchase_orders.select_record', ['doc' => $record->doc_num]) }}">
    </div>
@endif
