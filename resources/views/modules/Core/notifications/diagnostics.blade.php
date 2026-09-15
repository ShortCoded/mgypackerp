@extends('layouts.app')

@section('title', __('notifications.diagnostics.title'))

@section('content')
    @php
        $dates = app(\Modules\Core\Services\DateFormatService::class);
    @endphp
    <div class="container-xl">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h4 class="mb-1">{{ __('notifications.diagnostics.title') }}</h4>
                <p class="mb-0 text-600">{{ __('notifications.diagnostics.subtitle') }}</p>
            </div>
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.notifications.index') }}">{{ __('notifications.center_title') }}</a>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-4">
                <div class="card h-100"><div class="card-body">
                    <h6>{{ __('notifications.diagnostics.scheduler') }}</h6>
                    @if (is_array($runtime))
                        <div class="fw-semibold">{{ $runtime['status'] ?? 'unknown' }}</div>
                        <div class="small text-600">{{ $runtime['finished_at'] ?? $runtime['started_at'] ?? '' }}</div>
                        <div class="small text-600">{{ __('notifications.diagnostics.created', ['count' => (int) ($runtime['created_count'] ?? 0)]) }}</div>
                        @if ($runtime['error_code'] ?? null)<code>{{ $runtime['error_code'] }}</code>@endif
                    @else
                        <div class="text-600">{{ __('notifications.diagnostics.not_run') }}</div>
                    @endif
                </div></div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100"><div class="card-body">
                    <h6>{{ __('notifications.diagnostics.queue') }}</h6>
                    <div>{{ __('notifications.diagnostics.connection') }}: <code>{{ $configuration['queue_connection'] }}</code></div>
                    <div>{{ __('notifications.diagnostics.pending_jobs') }}: {{ $queue['pending'] ?? '—' }}</div>
                    <div>{{ __('notifications.diagnostics.failed_jobs') }}: {{ $queue['failed'] ?? '—' }}</div>
                </div></div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100"><div class="card-body">
                    <h6>{{ __('notifications.diagnostics.configuration') }}</h6>
                    @foreach (['vapid_subject', 'vapid_public_key', 'vapid_private_key'] as $key)
                        <div class="d-flex justify-content-between"><code>{{ $key }}</code><span class="badge badge-subtle-{{ $configuration[$key] ? 'success' : 'warning' }}">{{ __('notifications.diagnostics.'.($configuration[$key] ? 'configured' : 'missing')) }}</span></div>
                    @endforeach
                </div></div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header border-bottom"><h6 class="mb-0">{{ __('notifications.diagnostics.events') }}</h6></div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead><tr><th>{{ __('notifications.diagnostics.event') }}</th><th>{{ __('notifications.diagnostics.type') }}</th><th>{{ __('notifications.diagnostics.recipient_heading') }}</th><th>Push</th><th>{{ __('notifications.diagnostics.time') }}</th></tr></thead>
                            <tbody>
                                @forelse ($events as $event)
                                    <tr>
                                        <td><code>{{ \Illuminate\Support\Str::limit($event->event_uuid, 12) }}</code></td>
                                        <td>{{ $event->type }}</td>
                                        <td>{{ __('notifications.diagnostics.recipients', ['count' => $event->recipient_count]) }}</td>
                                        <td><span class="text-success">{{ $event->accepted_count }}</span> / <span class="text-warning">{{ $event->pending_count }}</span> / <span class="text-danger">{{ $event->failed_count }}</span></td>
                                        <td class="text-nowrap">{{ $dates->formatDateTime($event->occurred_at, '') }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-600 py-4" colspan="5">{{ __('notifications.empty') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header border-bottom"><h6 class="mb-0">{{ __('notifications.diagnostics.push_states') }}</h6></div>
                    <div class="list-group list-group-flush">
                        @forelse ($pushStatuses as $status => $count)
                            <div class="list-group-item d-flex justify-content-between"><code>{{ $status }}</code><span>{{ $count }}</span></div>
                        @empty
                            <div class="list-group-item text-600">{{ __('notifications.empty') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
