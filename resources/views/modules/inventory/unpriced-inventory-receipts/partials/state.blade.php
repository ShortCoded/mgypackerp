@php
    $type = $type ?? 'status';
@endphp

@if($type === 'pricing')
    @if(($record->pricing_status ?? 'unpriced') === 'priced')
        <span class="badge rounded-pill badge-subtle-success">{{ __('inventory.unpriced_inventory_receipts.pricing_statuses.priced') }}</span>
    @else
        <span class="badge rounded-pill badge-subtle-warning">{{ __('inventory.unpriced_inventory_receipts.pricing_statuses.unpriced') }}</span>
    @endif
@else
    @if($record->trashed())
        <span class="badge rounded-pill badge-subtle-danger">{{ __('inventory.unpriced_inventory_receipts.statuses.deleted') }}</span>
    @elseif($record->isCancelled())
        <span class="badge rounded-pill badge-subtle-danger">{{ __('inventory.unpriced_inventory_receipts.statuses.cancelled') }}</span>
    @elseif($record->isClosed())
        <span class="badge rounded-pill badge-subtle-secondary">{{ __('inventory.unpriced_inventory_receipts.statuses.closed') }}</span>
    @elseif($record->isApproved())
        <span class="badge rounded-pill badge-subtle-success">{{ __('inventory.unpriced_inventory_receipts.statuses.approved') }}</span>
    @else
        <span class="badge rounded-pill badge-subtle-info">{{ __('inventory.unpriced_inventory_receipts.statuses.draft') }}</span>
    @endif
@endif
