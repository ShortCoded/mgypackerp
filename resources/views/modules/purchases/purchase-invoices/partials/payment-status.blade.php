@php
    $status = (string) $record->payment_status;
    $class = match ($status) {
        'paid' => 'success',
        'partially_paid' => 'warning',
        default => 'secondary',
    };
@endphp

<x-status-indicator :status="$status" :label="__('purchase_invoices.payment_statuses.'.$status)" :tone="$class" />
