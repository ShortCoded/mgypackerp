@php
    $classes = [
        'received' => 'primary',
        'deposited' => 'info',
        'collected' => 'success',
        'draft' => 'secondary',
        'issued' => 'primary',
        'delivered' => 'info',
        'cleared' => 'success',
        'returned' => 'warning',
        'cancelled' => 'danger',
    ];
    $status = $record->trashed() ? 'deleted' : $record->status;
@endphp

<span class="badge rounded-pill badge-subtle-{{ $record->trashed() ? 'dark' : ($classes[$record->status] ?? 'secondary') }}">
    {{ __('cheques.statuses.'.$status) }}
</span>
