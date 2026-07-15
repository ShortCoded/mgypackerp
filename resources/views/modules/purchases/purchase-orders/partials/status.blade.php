@php
    $status = $record->trashed() ? 'deleted' : ($record->status ?? 'draft');
    $class = match ($status) {
        'approved' => 'success',
        'closed' => 'primary',
        'cancelled', 'deleted' => 'danger',
        default => 'secondary',
    };
@endphp

<span class="badge badge-subtle-{{ $class }}">{{ __('purchase_orders.statuses.'.$status) }}</span>
