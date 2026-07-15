@extends('layouts.app')

@php
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
@endphp

@section('title', __('auth_sessions.title'))

@section('content')
    <x-admin.report.page
        class="auth-sessions-report"
        :title="__('auth_sessions.report_title')"
        :description="__('auth_sessions.messages.summary_scope')"
    >
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="auth-sessions-filter-panel"
                :filter-title="__('auth_sessions.actions.toggle_filters')"
                :export-options="[
                    [
                        'permission' => 'auth.sessions.export',
                        'url' => route('admin.auth-sessions.export.excel'),
                        'label' => __('reports.export_excel'),
                    ],
                    [
                        'permission' => 'auth.sessions.export',
                        'url' => route('admin.auth-sessions.export.csv'),
                        'label' => __('reports.export_csv'),
                    ],
                    [
                        'permission' => 'auth.sessions.pdf',
                        'url' => route('admin.auth-sessions.export.pdf'),
                        'label' => __('reports.export_pdf'),
                        'newTab' => true,
                    ],
                ]"
            />
        </x-slot:actions>

        <x-admin.report.filter-panel
            id="auth-sessions-filter-panel"
            :title="__('reports.filters')"
            :description="__('auth_sessions.messages.filters_hint')"
        >
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="session-date-from">{{ __('reports.from_date') }}</label>
                <div class="input-group input-group-sm w-100 report-date-input-group">
                    <input class="form-control form-control-sm js-date-picker js-report-filter-control" id="session-date-from" name="date_from" type="text" data-filter-label="{{ __('reports.from_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr">
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="session-date-to">{{ __('reports.to_date') }}</label>
                <div class="input-group input-group-sm w-100 report-date-input-group">
                    <input class="form-control form-control-sm js-date-picker js-report-filter-control" id="session-date-to" name="date_to" type="text" data-filter-label="{{ __('reports.to_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr">
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="session-status">{{ __('auth_sessions.filters.status') }}</label>
                <select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="session-status" name="status" data-filter-label="{{ __('auth_sessions.filters.status') }}" data-url="{{ route('admin.auth-sessions.filter-options.presence-statuses') }}" data-placeholder="{{ __('auth_sessions.placeholders.select_presence') }}" data-allow-clear="true">
                    <option value=""></option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="session-account-status">{{ __('auth_sessions.filters.account_status') }}</label>
                <select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="session-account-status" name="account_status" data-filter-label="{{ __('auth_sessions.filters.account_status') }}" data-url="{{ route('admin.auth-sessions.filter-options.account-statuses') }}" data-placeholder="{{ __('auth_sessions.placeholders.select_account_status') }}" data-allow-clear="true">
                    <option value=""></option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="session-user">{{ __('auth_sessions.filters.user') }}</label>
                <select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="session-user" name="user" data-filter-label="{{ __('auth_sessions.filters.user') }}" data-url="{{ route('admin.auth-sessions.filter-options.users') }}" data-placeholder="{{ __('auth_sessions.placeholders.select_user') }}" data-allow-clear="true">
                    <option value=""></option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="session-device-type">{{ __('auth_sessions.filters.device_type') }}</label>
                <select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="session-device-type" name="device_type" data-filter-label="{{ __('auth_sessions.filters.device_type') }}" data-url="{{ route('admin.auth-sessions.filter-options.devices') }}" data-placeholder="{{ __('auth_sessions.placeholders.select_device') }}" data-allow-clear="true">
                    <option value=""></option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="session-browser-name">{{ __('auth_sessions.filters.browser_name') }}</label>
                <select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="session-browser-name" name="browser_name" data-filter-label="{{ __('auth_sessions.filters.browser_name') }}" data-url="{{ route('admin.auth-sessions.filter-options.browsers') }}" data-placeholder="{{ __('auth_sessions.placeholders.select_browser') }}" data-allow-clear="true">
                    <option value=""></option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="session-os-name">{{ __('auth_sessions.filters.os_name') }}</label>
                <select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="session-os-name" name="os_name" data-filter-label="{{ __('auth_sessions.filters.os_name') }}" data-url="{{ route('admin.auth-sessions.filter-options.operating-systems') }}" data-placeholder="{{ __('auth_sessions.placeholders.select_os') }}" data-allow-clear="true">
                    <option value=""></option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="session-ip">{{ __('auth_sessions.filters.ip') }}</label>
                <input class="form-control form-control-sm js-report-filter-control" id="session-ip" name="ip" type="text" data-filter-label="{{ __('auth_sessions.filters.ip') }}" placeholder="{{ __('auth_sessions.placeholders.ip_address') }}">
            </div>
        </x-admin.report.filter-panel>

        <x-admin.report.table-card
            :title="__('auth_sessions.table_title')"
            table-id="auth-sessions-table"
            :data-url="route('admin.auth-sessions.data')"
        >
            <thead class="bg-100 text-900">
                <tr>
                    <th class="dt-user-cell">{{ __('auth_sessions.fields.user') }}</th>
                    <th>{{ __('auth_sessions.fields.branch') }}</th>
                    <th>{{ __('auth_sessions.fields.financial_period') }}</th>
                    <th>{{ __('auth_sessions.fields.account_status') }}</th>
                    <th>{{ __('auth_sessions.fields.presence') }}</th>
                    <th>{{ __('auth_sessions.fields.device') }}</th>
                    <th>{{ __('auth_sessions.fields.ip_address') }}</th>
                    <th>{{ __('auth_sessions.fields.login_at') }}</th>
                    <th>{{ __('auth_sessions.fields.last_seen_at') }}</th>
                    <th>{{ __('auth_sessions.fields.duration') }}</th>
                    <th class="no-colvis">{{ __('common.fields.actions') }}</th>
                </tr>
            </thead>
        </x-admin.report.table-card>
    </x-admin.report.page>

    @include('modules.auth.reports.details-modal')
@endsection

@push('scripts')
    <script>
        window.reportMessages = @js([
            'detailsLoadFailed' => __('reports.details_load_failed'),
            'forceLogoutConfirmTitle' => __('auth_sessions.messages.force_logout_confirm_title'),
            'forceLogoutConfirmText' => __('auth_sessions.messages.force_logout_confirm_text'),
            'forceLogoutConfirmYes' => __('auth_sessions.actions.end_session'),
            'forceLogoutSuccess' => __('auth_sessions.messages.force_logout_success'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'no' => __('common.actions.no'),
        ]);
        window.dataTableTranslations = @js(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/report-ui.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Auth/auth-sessions.js') }}"></script>
@endpush
