@php
    $variant = match ($status) {
        'draft' => 'secondary',
        'sent', 'under_review' => 'info',
        'accepted', 'converted' => 'success',
        'rejected', 'expired', 'cancelled' => 'danger',
        default => 'secondary',
    };
@endphp

<span class="badge rounded-pill badge-subtle-{{ $variant }}">{{ __("quotations.statuses.{$status}") }}</span>
