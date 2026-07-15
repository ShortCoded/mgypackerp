@if(($type ?? 'document') === 'approval')
    @if($record->approved)
        <span class="badge rounded-pill badge-subtle-success">{{ __('inventory.opening_stocks.statuses.approved') }}</span>
    @else
        <span class="badge rounded-pill badge-subtle-secondary">{{ __('inventory.opening_stocks.statuses.not_approved') }}</span>
    @endif
@else
    @if($record->trashed())
        <span class="badge rounded-pill badge-subtle-danger">{{ __('inventory.opening_stocks.statuses.deleted') }}</span>
    @else
        <span class="badge rounded-pill badge-subtle-info">{{ __('inventory.opening_stocks.statuses.'.($record->status ?? 'closed')) }}</span>
    @endif
@endif
