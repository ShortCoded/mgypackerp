@props([
    'status',
    'label' => null,
    'tone' => null,
])

@php
    $normalizedStatus = str((string) $status)->lower()->replace('-', '_')->toString();
    $resolvedTone = $tone ?? match ($normalizedStatus) {
        'approved', 'posted', 'paid', 'issued', 'sent', 'fully_received', 'fully_converted', 'closed' => 'success',
        'partially_paid', 'partially_received', 'partially_converted', 'pending_approval', 'pending_inspection' => 'warning',
        'cancelled', 'rejected', 'deleted', 'reversed' => 'danger',
        default => 'primary',
    };
@endphp

<span {{ $attributes->class(['erp-status-indicator', 'erp-status-indicator--'.$resolvedTone]) }}>
    <span class="erp-status-indicator__dot" aria-hidden="true"></span>
    <span>{{ $label ?? __(str($normalizedStatus)->replace('_', ' ')->title()->toString()) }}</span>
</span>
