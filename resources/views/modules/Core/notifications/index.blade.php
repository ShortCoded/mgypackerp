@extends('layouts.app')

@section('title', __('notifications.center_title'))

@section('content')
    <div class="container-xl">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h4 class="mb-1">{{ __('notifications.center_title') }}</h4>
                <p class="mb-0 text-600">{{ __('notifications.sound.title') }} · {{ __('notifications.push.title') }}</p>
            </div>
            <div class="d-flex gap-2">
                @can('settings.pwa.view')
                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.notifications.diagnostics') }}"><span class="fas fa-stethoscope me-1"></span>{{ __('notifications.actions.diagnostics') }}</a>
                @endcan
                <button class="btn btn-falcon-default btn-sm" type="button" data-notifications-read-all>
                    <span class="fas fa-check-double me-1"></span>{{ __('notifications.actions.mark_all_read') }}
                </button>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-4">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="form-label mb-0" for="notification-volume">{{ __('notifications.sound.volume') }}</label>
                            <button class="btn btn-sm btn-falcon-default" type="button" data-notification-sound-toggle data-label-on="{{ __('notifications.sound.on') }}" data-label-off="{{ __('notifications.sound.off') }}">
                                <span class="fas fa-volume-mute me-1" data-notification-sound-icon></span><span data-notification-sound-label>{{ __('notifications.sound.off') }}</span>
                            </button>
                        </div>
                        <input class="form-range" id="notification-volume" type="range" min="0" max="100" step="5" data-notification-sound-volume>
                        <div class="small text-600" role="status" aria-live="polite" data-notification-sound-status></div>
                    </div>
                    <div class="col-lg-4">
                        <div class="d-flex flex-wrap gap-2">
                            <button class="btn btn-sm btn-falcon-default" type="button" data-notification-sound-test="chat">{{ __('notifications.sound.test_chat') }}</button>
                            <button class="btn btn-sm btn-falcon-default" type="button" data-notification-sound-test="action">{{ __('notifications.sound.test_action') }}</button>
                            <button class="btn btn-sm btn-falcon-warning" type="button" data-notification-sound-test="urgent">{{ __('notifications.sound.test_urgent') }}</button>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <button class="btn btn-sm btn-falcon-default" type="button" data-push-notification-toggle aria-pressed="false">
                            <span class="fas fa-bell-slash me-1" data-push-notification-icon></span><span data-push-notification-label>{{ __('notifications.push.enable') }}</span>
                        </button>
                        <div class="small text-600 mt-2" role="status" aria-live="polite" data-push-notification-status></div>
                    </div>
                </div>
            </div>
        </div>

        <form class="card mb-3" method="GET" action="{{ route('admin.notifications.index') }}">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-3"><label class="form-label" for="notification-search">{{ __('notifications.filters.search') }}</label><input class="form-control" id="notification-search" name="search" value="{{ $filters['search'] ?? '' }}"></div>
                    <div class="col-sm-6 col-lg-2"><label class="form-label" for="notification-module">{{ __('notifications.filters.module') }}</label><select class="form-select" id="notification-module" name="module"><option value="">{{ __('notifications.filters.all_modules') }}</option>@foreach ($modules as $module)<option value="{{ $module }}" @selected(($filters['module'] ?? '') === $module)>{{ __('notifications.modules.'.$module) }}</option>@endforeach</select></div>
                    <div class="col-sm-6 col-lg-2"><label class="form-label" for="notification-type">{{ __('notifications.filters.type') }}</label><select class="form-select" id="notification-type" name="type"><option value="">{{ __('notifications.filters.all_types') }}</option>@foreach ($types as $type)<option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ $type }}</option>@endforeach</select></div>
                    <div class="col-sm-6 col-lg-2"><label class="form-label" for="notification-state">{{ __('notifications.filters.state') }}</label><select class="form-select" id="notification-state" name="state">@foreach (['all', 'unread', 'read'] as $state)<option value="{{ $state }}" @selected(($filters['state'] ?? 'all') === $state)>{{ __('notifications.filters.'.$state) }}</option>@endforeach</select></div>
                    <div class="col-sm-6 col-lg-2"><label class="form-label" for="notification-from">{{ __('notifications.filters.from') }}</label><input class="form-control" id="notification-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}"></div>
                    <div class="col-sm-6 col-lg-2"><label class="form-label" for="notification-to">{{ __('notifications.filters.to') }}</label><input class="form-control" id="notification-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}"></div>
                </div>
                <div class="d-flex gap-2 mt-3"><button class="btn btn-primary btn-sm" type="submit">{{ __('notifications.filters.apply') }}</button><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.notifications.index') }}">{{ __('notifications.filters.reset') }}</a></div>
            </div>
        </form>

        <div class="card">
            <div class="list-group list-group-flush">
                @forelse ($notifications as $notification)
                    <a class="list-group-item list-group-item-action py-3 {{ $notification->read_at ? '' : 'bg-light' }}" href="{{ route('admin.notifications.open', $notification) }}">
                        <div class="d-flex justify-content-between gap-3">
                            <div class="min-w-0">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><span class="fw-semibold">{{ $notification->title }}</span>@if ($notification->requires_action)<span class="badge badge-subtle-warning">{{ __('notifications.requires_action') }}</span>@endif@if ($notification->module)<span class="badge badge-subtle-secondary">{{ __('notifications.modules.'.$notification->module) }}</span>@endif</div>
                                @if ($notification->body)<p class="mb-1 text-700">{{ $notification->body }}</p>@endif
                                <span class="small text-600">{{ app(\Modules\Core\Services\DateFormatService::class)->formatDateTime($notification->delivered_at ?: $notification->created_at) }}@if ($notification->branch) · {{ $notification->branch->name }}@endif</span>
                            </div>
                            <span class="fas fa-arrow-right text-500 mt-1" aria-hidden="true"></span>
                        </div>
                    </a>
                @empty
                    <div class="text-center text-600 p-5"><span class="far fa-bell fa-2x mb-3"></span><p class="mb-0">{{ __('notifications.empty') }}</p></div>
                @endforelse
            </div>
            @if ($notifications->hasPages())
                <div class="card-footer d-flex justify-content-between">
                    @if ($notifications->previousPageUrl())<a class="btn btn-sm btn-falcon-default" href="{{ $notifications->previousPageUrl() }}">{{ __('notifications.actions.previous') }}</a>@else<span></span>@endif
                    @if ($notifications->nextPageUrl())<a class="btn btn-sm btn-falcon-default" href="{{ $notifications->nextPageUrl() }}">{{ __('notifications.actions.next') }}</a>@endif
                </div>
            @endif
        </div>
    </div>
@endsection
