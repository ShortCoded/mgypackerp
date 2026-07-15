@extends('layouts.app')

@php
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
@endphp

@section('title', __('activity_logs.title'))

@section('content')
    <x-admin.report.page
        class="activity-logs-report"
        :title="__('activity_logs.report_title')"
        :description="__('activity_logs.messages.report_hint')"
    >
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="activity-logs-filter-panel"
                :filter-title="__('activity_logs.actions.toggle_filters')"
                :export-options="[
                    [
                        'permission' => 'activity.logs.export',
                        'url' => route('admin.activity-logs.export.excel'),
                        'label' => __('reports.export_excel'),
                    ],
                    [
                        'permission' => 'activity.logs.export',
                        'url' => route('admin.activity-logs.export.csv'),
                        'label' => __('reports.export_csv'),
                    ],
                    [
                        'permission' => 'activity.logs.pdf',
                        'url' => route('admin.activity-logs.export.pdf'),
                        'label' => __('reports.export_pdf'),
                        'newTab' => true,
                    ],
                ]"
            />
        </x-slot:actions>

        <x-admin.report.filter-panel
            id="activity-logs-filter-panel"
            :title="__('reports.filters')"
            :description="__('activity_logs.messages.filters_hint')"
        >
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="activity-date-from">{{ __('reports.from_date') }}</label>
                <div class="input-group input-group-sm w-100 report-date-input-group">
                    <input class="form-control form-control-sm js-date-picker js-report-filter-control" id="activity-date-from" name="date_from" type="text" data-filter-label="{{ __('reports.from_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr">
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="activity-date-to">{{ __('reports.to_date') }}</label>
                <div class="input-group input-group-sm w-100 report-date-input-group">
                    <input class="form-control form-control-sm js-date-picker js-report-filter-control" id="activity-date-to" name="date_to" type="text" data-filter-label="{{ __('reports.to_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr">
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="activity-causer">{{ __('activity_logs.filters.causer') }}</label>
                <select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="activity-causer" name="causer" data-filter-label="{{ __('activity_logs.filters.causer') }}" data-url="{{ route('admin.activity-logs.filter-options.users') }}" data-placeholder="{{ __('activity_logs.placeholders.select_user') }}" data-allow-clear="true">
                    <option value=""></option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="activity-area">{{ __('activity_logs.filters.area') }}</label>
                <select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="activity-area" name="area" data-filter-label="{{ __('activity_logs.filters.area') }}" data-url="{{ route('admin.activity-logs.filter-options.areas') }}" data-placeholder="{{ __('activity_logs.placeholders.select_area') }}" data-allow-clear="true">
                    <option value=""></option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="activity-action">{{ __('activity_logs.filters.action') }}</label>
                <select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="activity-action" name="action" data-filter-label="{{ __('activity_logs.filters.action') }}" data-url="{{ route('admin.activity-logs.filter-options.actions') }}" data-placeholder="{{ __('activity_logs.placeholders.select_activity') }}" data-allow-clear="true">
                    <option value=""></option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="activity-status">{{ __('activity_logs.filters.status') }}</label>
                <select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="activity-status" name="status" data-filter-label="{{ __('activity_logs.filters.status') }}" data-url="{{ route('admin.activity-logs.filter-options.statuses') }}" data-placeholder="{{ __('activity_logs.placeholders.select_result') }}" data-allow-clear="true">
                    <option value=""></option>
                </select>
            </div>
        </x-admin.report.filter-panel>

        <x-admin.report.table-card
            :title="__('activity_logs.table_title')"
            table-id="activity-logs-table"
            :data-url="route('admin.activity-logs.data')"
        >
            <thead class="bg-100 text-900">
                <tr>
                    <th class="dt-activity-log-date">{{ __('activity_logs.fields.date_time') }}</th>
                    <th class="dt-activity-log-user">{{ __('activity_logs.fields.user') }}</th>
                    <th class="dt-activity-log-area">{{ __('activity_logs.fields.area') }}</th>
                    <th class="dt-activity-log-activity">{{ __('activity_logs.fields.activity') }}</th>
                    <th class="dt-activity-log-result">{{ __('activity_logs.fields.result') }}</th>
                    <th class="dt-activity-log-record">{{ __('activity_logs.fields.record') }}</th>
                    <th class="dt-activity-log-summary">{{ __('activity_logs.fields.summary') }}</th>
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
    <script src="{{ asset('assets/js/modules/Auth/activity-logs.js') }}"></script>
@endpush
