@php
    $status = $record->trashed() ? 'deleted' : $record->fulfillmentStatus();
    $class = match ($status) {
        'approved', 'sent', 'fully_received' => 'success',
        'partially_received' => 'warning',
        'closed' => 'primary',
        'cancelled', 'deleted' => 'danger',
        default => 'secondary',
    };
@endphp

<x-status-indicator :status="$status" :label="__('purchase_orders.statuses.'.$status)" :tone="$class" />
