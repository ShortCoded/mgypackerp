@php
    $status = trim((string) $company->status);
    $statusClass = $status === 'active' ? 'success' : 'warning';
@endphp

@if ($company->trashed())
    <span class="badge rounded-pill badge-subtle-danger">{{ __('companies.trash.trashed') }}</span>
@elseif ($status !== '' && \Illuminate\Support\Facades\Lang::has("companies.statuses.{$status}"))
    <span class="badge rounded-pill badge-subtle-{{ $statusClass }}">{{ __("companies.statuses.{$status}") }}</span>
@endif
