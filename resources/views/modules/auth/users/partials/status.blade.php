@php
    $status = trim((string) $user->status);
    $statusClass = match ($status) {
        'active' => 'success',
        'blocked' => 'danger',
        default => 'warning',
    };
@endphp

@if ($status !== '' && \Illuminate\Support\Facades\Lang::has("users.statuses.{$status}"))
    <span class="badge rounded-pill badge-subtle-{{ $statusClass }}">{{ __("users.statuses.{$status}") }}</span>
@endif
