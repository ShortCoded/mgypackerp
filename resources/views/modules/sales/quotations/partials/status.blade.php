@php
    $variant = match ($status) {
        'draft' => 'secondary',
        'sent', 'under_review' => 'info',
        'accepted', 'approved', 'posted', 'confirmed', 'completed', 'fulfilled', 'received', 'inspected' => 'success',
        'converted', 'partially_delivered', 'partially_invoiced', 'partially_fulfilled', 'partially_converted' => 'primary',
        'pending_approval', 'expired', 'held_credit', 'pending_authorization' => 'warning',
        'rejected' => 'danger',
        'cancelled', 'closed' => 'secondary',
        default => 'secondary',
    };
@endphp

<span class="badge rounded-pill badge-subtle-{{ $variant }}">{{ \Illuminate\Support\Facades\Lang::has("quotations.statuses.{$status}") ? __("quotations.statuses.{$status}") : __(str($status)->replace("_", " ")->title()->toString()) }}</span>
