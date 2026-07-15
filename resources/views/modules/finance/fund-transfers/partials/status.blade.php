@php
    $classes = [
        'draft' => 'secondary',
        'approved' => 'success',
        'cancelled' => 'danger',
    ];
    $status = $record->trashed() ? 'deleted' : $record->status;
@endphp

<span class="badge rounded-pill badge-subtle-{{ $record->trashed() ? 'dark' : ($classes[$record->status] ?? 'secondary') }}">
    {{ __('fund_transfers.statuses.'.$status) }}
</span>
