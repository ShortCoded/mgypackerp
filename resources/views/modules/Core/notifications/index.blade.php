@extends('layouts.app')

@section('title', __('notifications.center_title'))

@section('content')
    <div class="container-xl" data-notifications-center>
        @if (session('warning'))
            <div class="alert alert-warning" role="alert">{{ session('warning') }}</div>
        @endif

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h4 class="mb-0">{{ __('notifications.center_title') }}</h4>
            <button class="btn btn-falcon-default btn-sm" type="button" data-notifications-read-all>
                <span class="fas fa-check-double me-1" aria-hidden="true"></span>{{ __('notifications.actions.mark_all_read') }}
            </button>
        </div>

        <div class="card mb-3">
            <div class="card-body py-2">
                <div class="row g-3 align-items-center">
                    <div class="col-12 col-lg-6" data-push-notification-control data-push-notification-explained>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="fw-semibold text-900">{{ __('notifications.push.title') }}</span>
                            <span class="small text-600 me-auto" role="status" aria-live="polite" id="notification-device-status" data-push-notification-status>{{ __('notifications.push.permission_default') }}</span>
                            <button class="btn btn-sm btn-falcon-default" type="button" data-push-notification-toggle aria-pressed="false" aria-describedby="notification-device-status">
                                <span class="fas fa-bell-slash me-1" data-push-notification-icon aria-hidden="true"></span><span data-push-notification-label>{{ __('notifications.push.enable') }}</span>
                            </button>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <label class="fw-semibold text-900 mb-0" for="notification-volume">{{ __('notifications.sound.title') }}</label>
                            <span class="small text-600" role="status" aria-live="polite" data-notification-sound-status></span>
                            <button class="btn btn-sm btn-falcon-default" type="button" data-notification-sound-toggle data-label-on="{{ __('notifications.sound.on') }}" data-label-off="{{ __('notifications.sound.off') }}" aria-pressed="false">
                                <span class="fas fa-volume-mute me-1" data-notification-sound-icon aria-hidden="true"></span><span data-notification-sound-label>{{ __('notifications.sound.off') }}</span>
                            </button>
                            <div class="d-flex align-items-center gap-2 flex-grow-1">
                                <span class="fas fa-volume-down text-500" aria-hidden="true"></span>
                                <x-forms.input class="flex-grow-1" id="notification-volume" type="range" min="0" max="100" step="5" data-notification-sound-volume aria-label="{{ __('notifications.sound.volume') }}" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <form class="card mb-3" method="GET" action="{{ route('admin.notifications.index') }}">
            <div class="card-body">
                <div class="row g-3 align-items-start">
                    <div class="col-12 col-md-6 col-lg-4 col-xl-2">
                        <label class="form-label" for="notification-search">{{ __('notifications.filters.search') }}</label>
                        <x-forms.input class="form-control form-control-sm" id="notification-search" name="search" value="{{ old('search', $filters['search'] ?? '') }}" />
                    </div>
                    <div class="col-12 col-md-6 col-lg-4 col-xl-2">
                        <label class="form-label" for="notification-module">{{ __('notifications.filters.module') }}</label>
                        <x-forms.select class="form-select form-select-sm w-100" id="notification-module" name="module" variant="local" :placeholder="__('notifications.filters.all_modules')" :allow-clear="true">
                            <option value=""></option>
                            @foreach ($modules as $module => $moduleLabel)
                                <option value="{{ $module }}" @selected(old('module', $filters['module'] ?? '') === $module)>{{ $moduleLabel }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-12 col-md-6 col-lg-4 col-xl-2">
                        <label class="form-label" for="notification-type">{{ __('notifications.filters.type') }}</label>
                        <x-forms.select class="form-select form-select-sm w-100" id="notification-type" name="type" variant="local" :placeholder="__('notifications.filters.all_types')" :allow-clear="true">
                            <option value=""></option>
                            @foreach ($types as $type => $typeLabel)
                                <option value="{{ $type }}" @selected(old('type', $filters['type'] ?? '') === $type)>{{ $typeLabel }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-12 col-md-6 col-lg-4 col-xl-2">
                        <label class="form-label" for="notification-state">{{ __('notifications.filters.state') }}</label>
                        <x-forms.select class="form-select form-select-sm w-100" id="notification-state" name="state" variant="local" :allow-clear="false">
                            @foreach (['all', 'unread', 'read'] as $state)
                                <option value="{{ $state }}" @selected(old('state', $filters['state'] ?? 'all') === $state)>{{ __('notifications.filters.'.$state) }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-12 col-md-6 col-lg-4 col-xl-2">
                        <label class="form-label" for="notification-from">{{ __('notifications.filters.from') }}</label>
                        <x-forms.date-input :class="'form-control form-control-sm'.($errors->has('from') ? ' is-invalid' : '')" id="notification-from" name="from" value="{{ old('from', $filters['from'] ?? '') }}" placeholder="{{ __('common.placeholders.select_date') }}" :aria-invalid="$errors->has('from') ? 'true' : null" :aria-describedby="$errors->has('from') ? 'notification-from-error' : null" />
                        @error('from')
                            <div class="invalid-feedback d-block" id="notification-from-error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12 col-md-6 col-lg-4 col-xl-2">
                        <label class="form-label" for="notification-to">{{ __('notifications.filters.to') }}</label>
                        <x-forms.date-input :class="'form-control form-control-sm'.($errors->has('to') ? ' is-invalid' : '')" id="notification-to" name="to" value="{{ old('to', $filters['to'] ?? '') }}" placeholder="{{ __('common.placeholders.select_date') }}" :aria-invalid="$errors->has('to') ? 'true' : null" :aria-describedby="$errors->has('to') ? 'notification-to-error' : null" />
                        @error('to')
                            <div class="invalid-feedback d-block" id="notification-to-error">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
                    <button class="btn btn-primary btn-sm" type="submit">{{ __('notifications.filters.apply') }}</button>
                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.notifications.index') }}">{{ __('notifications.filters.reset') }}</a>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="list-group list-group-flush">
                @forelse ($notifications as $notification)
                    @php
                        $moduleKey = $notification->module ? str_replace(['.', '-'], '_', $notification->module) : null;
                        $moduleTranslationKey = $moduleKey ? 'notifications.modules.'.$moduleKey : null;
                        $moduleLabel = $moduleTranslationKey && trans()->has($moduleTranslationKey)
                            ? __($moduleTranslationKey)
                            : ($notification->module ? __('notifications.modules.other') : null);
                    @endphp
                    <a class="list-group-item list-group-item-action py-3 {{ $notification->read_at ? '' : 'notification-unread' }}" href="{{ route('admin.notifications.open', $notification) }}">
                        <div class="d-flex justify-content-between gap-3">
                            <div class="min-w-0">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                    <span class="fw-semibold">{{ $notification->title }}</span>
                                    @if ($notification->requires_action)
                                        <span class="badge badge-subtle-warning">{{ __('notifications.requires_action') }}</span>
                                    @endif
                                    @if ($moduleLabel)
                                        <span class="badge badge-subtle-secondary">{{ $moduleLabel }}</span>
                                    @endif
                                </div>
                                @if ($notification->body)<p class="mb-1 text-700">{{ $notification->body }}</p>@endif
                                <span class="small text-600">{{ app(\Modules\Core\Services\DateFormatService::class)->formatDateTime($notification->delivered_at ?: $notification->created_at) }}@if ($notification->branch) · {{ $notification->branch->name }}@endif</span>
                            </div>
                            <span class="fas fa-arrow-{{ config('languages.available.'.app()->getLocale().'.dir') === 'rtl' ? 'left' : 'right' }} text-500 mt-1" aria-hidden="true"></span>
                        </div>
                    </a>
                @empty
                    <div class="text-center text-600 p-4">
                        <span class="far fa-bell fa-2x mb-3" aria-hidden="true"></span>
                        <p class="mb-0">{{ $hasActiveFilters ? __('notifications.empty_filtered') : __('notifications.empty') }}</p>
                    </div>
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
