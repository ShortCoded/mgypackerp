@extends('layouts.app')

@section('title', __('calendar.title'))

@php
    $isRtl = config('languages.available.' . app()->getLocale() . '.dir', 'ltr') === 'rtl';
    $calendarConfig = [
        'locale' => app()->getLocale(),
        'direction' => config('languages.available.' . app()->getLocale() . '.dir', 'ltr'),
        'timezone' => config('app.timezone', 'Africa/Cairo'),
        'initialDate' => $initialCalendarDate,
        'firstDay' => app()->getLocale() === 'ar' ? 6 : 0,
        'can' => [
            'create' => $canCreateCalendarEvent,
            'edit' => $canEditCalendarEvent,
            'delete' => $canDeleteCalendarEvent,
        ],
        'urls' => [
            'events' => route('admin.calendar.events'),
            'store' => route('admin.calendar.events.store'),
            'show' => route('admin.calendar.events.show', ['event' => '__EVENT__']),
            'update' => route('admin.calendar.events.update', ['event' => '__EVENT__']),
            'move' => route('admin.calendar.events.move', ['event' => '__EVENT__']),
            'status' => route('admin.calendar.events.status', ['event' => '__EVENT__']),
            'destroy' => route('admin.calendar.events.destroy', ['event' => '__EVENT__']),
        ],
        'labels' => [
            'createTitle' => __('calendar.actions.create'),
            'editTitle' => __('calendar.actions.edit'),
            'save' => __('calendar.actions.save'),
            'delete' => __('calendar.actions.delete'),
            'edit' => __('calendar.actions.edit'),
            'viewTitle' => __('calendar.actions.view_event'),
            'cancel' => __('common.actions.cancel'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'close' => __('common.actions.close'),
            'today' => __('calendar.actions.today'),
            'month' => __('calendar.view_titles.month'),
            'week' => __('calendar.view_titles.week'),
            'day' => __('calendar.view_titles.day'),
            'list' => __('calendar.view_titles.list'),
        ],
        'messages' => [
            'created' => __('calendar.messages.created'),
            'updated' => __('calendar.messages.updated'),
            'moved' => __('calendar.messages.moved'),
            'deleted' => __('calendar.messages.deleted'),
            'deleteConfirm' => __('calendar.messages.delete_confirm'),
            'couldNotLoad' => __('calendar.messages.could_not_load'),
            'couldNotSave' => __('calendar.messages.could_not_save'),
            'couldNotUpdate' => __('calendar.messages.could_not_update'),
            'couldNotDelete' => __('calendar.messages.could_not_delete'),
            'forbiddenEdit' => __('calendar.messages.forbidden_edit'),
            'notAllowedManage' => __('calendar.messages.not_allowed_manage'),
            'requiredStart' => __('calendar.validation.starts_at_required'),
            'requiredTitle' => __('calendar.validation.title_required'),
            'validationFailed' => __('common.messages.validation_failed'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'noChanges' => __('common.messages.no_changes'),
            'loading' => __('common.messages.loading'),
        ],
        'fields' => [
            'allDay' => __('calendar.fields.all_day'),
            'createdAt' => __('calendar.fields.created_at'),
            'description' => __('calendar.fields.description'),
            'endsAt' => __('calendar.fields.ends_at'),
            'location' => __('calendar.fields.location'),
            'meetingUrl' => __('calendar.fields.meeting_url'),
            'reminderAt' => __('calendar.fields.reminder_at'),
            'startsAt' => __('calendar.fields.starts_at'),
            'status' => __('calendar.fields.status'),
            'title' => __('calendar.fields.title'),
            'updatedAt' => __('calendar.fields.updated_at'),
        ],
        'shortcuts' => [
            'cancel' => __('calendar.shortcuts.cancel'),
            'delete' => __('calendar.shortcuts.delete'),
            'edit' => __('calendar.shortcuts.edit'),
        ],
    ];
    $calendarCssPath = 'assets/css/modules/Core/calendar.css';
    $calendarJsPath = 'assets/js/modules/Core/calendar.js';
@endphp

@push('styles')
    <link href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url($calendarCssPath) }}" rel="stylesheet">
@endpush

@section('content')

    @include('modules.core.calendar.partials.event-modal')
    @include('modules.core.calendar.partials.event-details-modal')

    <div class="card overflow-hidden erp-calendar-card">
        <div class="card-header">
            <div class="row gx-0 gy-2 align-items-center">
                <div class="col-auto d-flex justify-content-end order-md-1">
                    <button class="btn icon-item icon-item-sm shadow-none p-0 me-1 ms-md-2" type="button" data-calendar-action="prev" data-bs-toggle="tooltip" title="{{ __('calendar.actions.previous') }}">
                        <span class="fas {{ $isRtl ? 'fa-arrow-right' : 'fa-arrow-left' }}"></span>
                    </button>
                    <button class="btn icon-item icon-item-sm shadow-none p-0 me-1 me-lg-2" type="button" data-calendar-action="next" data-bs-toggle="tooltip" title="{{ __('calendar.actions.next') }}">
                        <span class="fas {{ $isRtl ? 'fa-arrow-left' : 'fa-arrow-right' }}"></span>
                    </button>
                </div>
                <div class="col-auto col-md-auto order-md-2">
                    <h4 class="mb-0 fs-9 fs-sm-8 fs-lg-7 calendar-title"></h4>
                </div>
                <div class="col col-md-auto d-flex justify-content-end order-md-3">
                    <button class="btn btn-falcon-primary btn-sm" type="button" data-calendar-action="today" data-shortcut-action="calendar.today" title="{{ __('calendar.shortcuts.today') }}" data-bs-title="{{ __('calendar.shortcuts.today') }}">{{ __('calendar.actions.today') }}</button>
                </div>
                <div class="col-md-auto d-md-none">
                    <hr class="my-2">
                </div>
                @if ($canCreateCalendarEvent)
                    <div class="col-auto d-flex order-md-0">
                        <button id="btn_add_record" class="btn btn-primary btn-sm js-calendar-add" type="button" data-shortcut-action="calendar.create" title="{{ __('calendar.shortcuts.add') }}" data-bs-title="{{ __('calendar.shortcuts.add') }}">
                            <span class="fas fa-plus me-2"></span>{{ __('calendar.actions.add') }}
                        </button>
                    </div>
                @endif
                <div class="col d-flex justify-content-end order-md-2">
                    <div class="dropdown font-sans-serif me-md-2">
                        <button class="btn btn-falcon-default text-600 btn-sm dropdown-toggle dropdown-caret-none" type="button" id="calendar-view-filter" data-bs-toggle="dropdown" data-boundary="viewport" aria-haspopup="true" aria-expanded="false">
                            <span data-calendar-view-title>{{ __('calendar.view_titles.month') }}</span><span class="fas fa-sort ms-2 fs-10"></span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end border py-2" aria-labelledby="calendar-view-filter">
                            <a class="active dropdown-item d-flex justify-content-between" href="#!" data-calendar-view="dayGridMonth">{{ __('calendar.view_titles.month') }}<span class="icon-check"><span class="fas fa-check" data-fa-transform="down-4 shrink-4"></span></span></a>
                            <a class="dropdown-item d-flex justify-content-between" href="#!" data-calendar-view="timeGridWeek">{{ __('calendar.view_titles.week') }}<span class="icon-check"><span class="fas fa-check" data-fa-transform="down-4 shrink-4"></span></span></a>
                            <a class="dropdown-item d-flex justify-content-between" href="#!" data-calendar-view="timeGridDay">{{ __('calendar.view_titles.day') }}<span class="icon-check"><span class="fas fa-check" data-fa-transform="down-4 shrink-4"></span></span></a>
                            <a class="dropdown-item d-flex justify-content-between" href="#!" data-calendar-view="listWeek">{{ __('calendar.view_titles.list') }}<span class="icon-check"><span class="fas fa-check" data-fa-transform="down-4 shrink-4"></span></span></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body p-0 scrollbar">
            <div class="calendar-outline app-calendar" id="erpCalendar"></div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.AppCalendar = @json($calendarConfig);
    </script>
    <script src="{{ asset('vendors/fullcalendar/index.global.min.js') }}"></script>
    <script src="{{ asset('vendors/dayjs/dayjs.min.js') }}"></script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url($calendarJsPath) }}"></script>
@endpush
