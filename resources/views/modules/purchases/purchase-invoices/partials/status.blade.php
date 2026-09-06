@php
    $status = $record->trashed() ? 'deleted' : (string) $record->status;
    $class = match ($status) {
        'approved' => 'success',
        'closed' => 'primary',
        'cancelled', 'deleted' => 'danger',
        default => 'secondary',
    };
@endphp

<x-status-indicator :status="$status" :label="__('purchase_invoices.statuses.'.$status)" :tone="$class" />
