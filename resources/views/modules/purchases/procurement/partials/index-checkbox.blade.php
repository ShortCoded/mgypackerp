@if($screen === 'purchase_requisitions' && ($isAdministrativeBranch ?? false) && !$record->trashed() && in_array($record->status, ['approved', 'partially_converted']) && auth()->user()?->can('purchase_orders.create') && auth()->user()?->can('purchases.prices.view'))
<div class="form-check mb-0"><x-forms.input class="form-check-input js-procurement-select" type="checkbox" value="{{ $record->doc_num }}" aria-label="{{ __('Select') }} {{ $record->doc_num }}" /></div>
@endif
