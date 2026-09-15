@if($record->trashed())
    <span class="badge rounded-pill badge-subtle-danger">{{ __('inventory.stock_counts.statuses.deleted') }}</span>
@elseif($record->status === \Modules\Inventory\Models\StockCount::StatusApproved)
    <span class="badge rounded-pill badge-subtle-success">{{ __('inventory.stock_counts.statuses.approved') }}</span>
@elseif($record->status === \Modules\Inventory\Models\StockCount::StatusCounted)
    <span class="badge rounded-pill badge-subtle-info">{{ __('inventory.stock_counts.statuses.counted') }}</span>
@else
    <span class="badge rounded-pill badge-subtle-secondary">{{ __('inventory.stock_counts.statuses.draft') }}</span>
@endif
