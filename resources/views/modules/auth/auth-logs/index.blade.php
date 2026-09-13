@extends('layouts.app')

@php
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
@endphp

@section('title', __('auth_logs.title'))

@section('content')
    <x-admin.report.page class="auth-logs-report" :title="__('auth_logs.report_title')">
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="auth-logs-filter-panel"
                :filter-title="__('auth_logs.actions.toggle_filters')"
                :export-options="[
                    [
                        'permission' => 'auth.logs.export',
                        'url' => route('admin.auth-logs.export.excel'),
                        'label' => __('reports.export_excel'),
                    ],
                    [
                        'permission' => 'auth.logs.export',
                        'url' => route('admin.auth-logs.export.csv'),
                        'label' => __('reports.export_csv'),
                    ],
                    [
                        'permission' => 'auth.logs.pdf',
                        'url' => route('admin.auth-logs.export.pdf'),
                        'label' => __('reports.export_pdf'),
                        'newTab' => true,
                    ],
                ]"
            />
        </x-slot:actions>

        <x-admin.report.filter-panel
            id="auth-logs-filter-panel"
            :title="__('reports.filters')"
            :description="__('auth_logs.messages.filters_hint')"
        >
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="auth-date-from">{{ __('reports.from_date') }}</label>
                <div class="input-group input-group-sm w-100 report-date-input-group">
                    <x-forms.date-input class="form-control form-control-sm js-date-picker js-report-filter-control" id="auth-date-from" name="date_from" type="text" data-filter-label="{{ __('reports.from_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" />
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="auth-date-to">{{ __('reports.to_date') }}</label>
                <div class="input-group input-group-sm w-100 report-date-input-group">
                    <x-forms.date-input class="form-control form-control-sm js-date-picker js-report-filter-control" id="auth-date-to" name="date_to" type="text" data-filter-label="{{ __('reports.to_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" />
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="auth-user">{{ __('auth_logs.filters.user') }}</label>
                <x-forms.select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="auth-user" name="user" data-filter-label="{{ __('auth_logs.filters.user') }}" data-url="{{ route('admin.auth-logs.filter-options.users') }}" data-placeholder="{{ __('auth_logs.placeholders.select_user') }}" data-allow-clear="true">
                    <option value=""></option>
                </x-forms.select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="auth-event">{{ __('auth_logs.filters.event') }}</label>
                <x-forms.select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="auth-event" name="event" data-filter-label="{{ __('auth_logs.filters.event') }}" data-url="{{ route('admin.auth-logs.filter-options.events') }}" data-placeholder="{{ __('auth_logs.placeholders.select_event') }}" data-allow-clear="true">
                    <option value=""></option>
                </x-forms.select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="auth-status">{{ __('auth_logs.filters.status') }}</label>
                <x-forms.select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="auth-status" name="status" data-filter-label="{{ __('auth_logs.filters.status') }}" data-url="{{ route('admin.auth-logs.filter-options.statuses') }}" data-placeholder="{{ __('auth_logs.placeholders.select_result') }}" data-allow-clear="true">
                    <option value=""></option>
                </x-forms.select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="auth-ip">{{ __('auth_logs.filters.ip') }}</label>
                <x-forms.input class="form-control form-control-sm js-report-filter-control" id="auth-ip" name="ip" type="text" data-filter-label="{{ __('auth_logs.filters.ip') }}" placeholder="{{ __('auth_logs.placeholders.ip_address') }}" />
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="auth-failure-reason">{{ __('auth_logs.filters.failure_reason') }}</label>
                <x-forms.select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="auth-failure-reason" name="failure_reason" data-filter-label="{{ __('auth_logs.filters.failure_reason') }}" data-url="{{ route('admin.auth-logs.filter-options.failure-reasons') }}" data-placeholder="{{ __('auth_logs.placeholders.select_failure_reason') }}" data-allow-clear="true">
                    <option value=""></option>
                </x-forms.select>
            </div>
        </x-admin.report.filter-panel>

        <x-admin.report.table-card
            :title="__('auth_logs.table_title')"
            table-id="auth-logs-table"
            :data-url="route('admin.auth-logs.data')"
        >
            <thead class="bg-100 text-900">
                <tr>
                    <th class="dt-auth-log-date">{{ __('auth_logs.fields.date_time') }}</th>
                    <th class="dt-auth-log-user">{{ __('auth_logs.fields.user') }}</th>
                    <th>{{ __('auth_logs.fields.activity') }}</th>
                    <th>{{ __('auth_logs.fields.result') }}</th>
                    <th>{{ __('auth_logs.fields.ip_address') }}</th>
                    <th>{{ __('auth_logs.fields.location') }}</th>
                    <th class="dt-auth-log-device">{{ __('auth_logs.fields.device') }}</th>
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
            'unexpectedError' => __('common.messages.unexpected_error'),
        ]);
        window.dataTableTranslations = @js(__('datatables'));
    </script>
    <script src="{{ asset('assets/js/modules/Core/report-ui.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Auth/auth-logs.js') }}"></script>
@endpush
