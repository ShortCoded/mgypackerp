@if($record->trashed())
    <span class="badge rounded-pill badge-subtle-danger">{{ __('inventory.opening_stock_pricings.statuses.deleted') }}</span>
@else
    <span class="badge rounded-pill badge-subtle-info">{{ __('inventory.opening_stock_pricings.statuses.'.($record->status ?? 'closed')) }}</span>
@endif
