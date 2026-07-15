@php
    $status = $record->trashed() ? 'deleted' : (string) $record->status;
    $class = match ($status) {
        'approved' => 'badge-subtle-success text-success',
        'closed' => 'badge-subtle-primary text-primary',
        'cancelled', 'deleted' => 'badge-subtle-danger text-danger',
        default => 'badge-subtle-secondary text-secondary',
    };
@endphp

<span class="badge rounded-pill {{ $class }}">{{ __('purchase_invoices.statuses.'.$status) }}</span>
