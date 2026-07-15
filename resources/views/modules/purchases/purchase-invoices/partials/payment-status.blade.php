@php
    $status = (string) $record->payment_status;
    $class = match ($status) {
        'paid' => 'badge-subtle-success text-success',
        'partially_paid' => 'badge-subtle-warning text-warning',
        default => 'badge-subtle-secondary text-secondary',
    };
@endphp

<span class="badge rounded-pill {{ $class }}">{{ __('purchase_invoices.payment_statuses.'.$status) }}</span>
