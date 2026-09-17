@extends('layouts.app')

@php
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $staticSelectClass = 'form-select form-select-sm w-100 js-select2-local js-report-filter-control';
    $ajaxSelectClass = 'form-select form-select-sm w-100 js-select2-ajax js-report-filter-control';
    $reportId = $report['key'].'-data-report';
    $datatableTranslations = json_encode(__('datatables'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
@endphp

@section('title', $report['title'])

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/modules/business-partner-data-report.css') }}">
@endpush

@section('content')
    <x-admin.report.page
        class="business-partner-data-report {{ $reportId }}"
        :title="$report['title']"
        :description="$report['description']"
        :data-datatable-translations="$datatableTranslations"
    >
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                :filter-target="$reportId.'-filter-panel'"
                :filter-title="__('business_partner_reports.actions.toggle_filters')"
                :export-options="[
                    [
                        'permission' => $report['permission_prefix'].'.export',
                        'url' => route($report['route_prefix'].'.export.excel'),
                        'label' => __('reports.export_excel'),
                        'icon' => 'file-excel',
                    ],
                    [
                        'permission' => $report['permission_prefix'].'.export',
                        'url' => route($report['route_prefix'].'.export.csv'),
                        'label' => __('reports.export_csv'),
                        'icon' => 'file-csv',
                    ],
                    [
                        'permission' => $report['permission_prefix'].'.pdf',
                        'url' => route($report['route_prefix'].'.export.pdf'),
                        'label' => __('reports.export_pdf'),
                        'icon' => 'file-pdf',
                        'newTab' => true,
                    ],
                ]"
            />
        </x-slot:actions>

        <x-admin.report.filter-panel
            :id="$reportId.'-filter-panel'"
            :title="__('reports.filters')"
            :description="$report['description']"
        >
            <div class="col-12">
                <div class="business-partner-filter-group-heading is-first">
                    <span class="fas fa-address-card"></span>
                    <span>{{ __('business_partner_reports.filter_groups.identification') }}</span>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="{{ $reportId }}-doc-num">{{ $report['filter_labels']['doc_num'] }}</label>
                <x-forms.input class="form-control form-control-sm js-report-filter-control" id="{{ $reportId }}-doc-num" name="doc_num" type="text" data-filter-label="{{ $report['filter_labels']['doc_num'] }}" placeholder="{{ __('business_partner_reports.'.$report['key'].'.placeholders.doc_num') }}" dir="ltr" />
            </div>

            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="{{ $reportId }}-name">{{ $report['filter_labels']['name'] }}</label>
                <x-forms.input class="form-control form-control-sm js-report-filter-control" id="{{ $reportId }}-name" name="name" type="text" data-filter-label="{{ $report['filter_labels']['name'] }}" placeholder="{{ __('business_partner_reports.'.$report['key'].'.placeholders.name') }}" />
            </div>

            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="{{ $reportId }}-phone">{{ $report['filter_labels']['phone'] }}</label>
                <x-forms.input class="form-control form-control-sm js-report-filter-control" id="{{ $reportId }}-phone" name="phone" type="text" data-filter-label="{{ $report['filter_labels']['phone'] }}" placeholder="{{ __('business_partner_reports.placeholders.phone') }}" dir="ltr" />
            </div>

            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="{{ $reportId }}-status">{{ $report['filter_labels']['status'] }}</label>
                <x-forms.select class="{{ $staticSelectClass }}" id="{{ $reportId }}-status" name="status" data-filter-label="{{ $report['filter_labels']['status'] }}" data-placeholder="{{ __('reports.all_records') }}" data-allow-clear="true">
                    <option value=""></option>
                    <option value="active">{{ __('business_partners.statuses.active') }}</option>
                    <option value="inactive">{{ __('business_partners.statuses.inactive') }}</option>
                </x-forms.select>
            </div>

            <div class="col-12">
                <div class="business-partner-filter-group-heading">
                    <span class="fas fa-sitemap"></span>
                    <span>{{ __('business_partner_reports.filter_groups.accounting') }}</span>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="{{ $reportId }}-account-group">{{ $report['filter_labels']['account_group_doc_num'] }}</label>
                <x-forms.select class="{{ $ajaxSelectClass }}" id="{{ $reportId }}-account-group" name="account_group_doc_num" data-filter-label="{{ $report['filter_labels']['account_group_doc_num'] }}" data-url="{{ route($report['route_prefix'].'.filter-options.account-groups') }}" data-placeholder="{{ __('business_partner_reports.placeholders.select_group') }}" data-allow-clear="true">
                    <option value=""></option>
                </x-forms.select>
            </div>

            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="{{ $reportId }}-account">{{ $report['filter_labels']['account_doc_num'] }}</label>
                <x-forms.select class="{{ $ajaxSelectClass }}" id="{{ $reportId }}-account" name="account_doc_num" data-filter-label="{{ $report['filter_labels']['account_doc_num'] }}" data-url="{{ route($report['route_prefix'].'.filter-options.accounts') }}" data-placeholder="{{ __('business_partner_reports.placeholders.select_account') }}" data-allow-clear="true">
                    <option value=""></option>
                </x-forms.select>
            </div>

            <div class="col-12">
                <div class="business-partner-filter-group-heading">
                    <span class="fas fa-map-marker-alt"></span>
                    <span>{{ __('business_partner_reports.filter_groups.geography') }}</span>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="{{ $reportId }}-country">{{ $report['filter_labels']['country_doc_num'] }}</label>
                <x-forms.select class="{{ $ajaxSelectClass }}" id="{{ $reportId }}-country" name="country_doc_num" data-filter-label="{{ $report['filter_labels']['country_doc_num'] }}" data-url="{{ route('admin.select2.countries') }}" data-placeholder="{{ __('business_partner_reports.placeholders.select_country') }}" data-allow-clear="true">
                    <option value=""></option>
                </x-forms.select>
            </div>

            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="{{ $reportId }}-governorate">{{ $report['filter_labels']['governorate_doc_num'] }}</label>
                <x-forms.select class="{{ $ajaxSelectClass }}" id="{{ $reportId }}-governorate" name="governorate_doc_num" data-filter-label="{{ $report['filter_labels']['governorate_doc_num'] }}" data-url="{{ route('admin.select2.governorates') }}" data-placeholder="{{ __('business_partner_reports.placeholders.select_governorate') }}" data-allow-clear="true" data-depends-on="#{{ $reportId }}-country" data-dependent-param="country_doc_num" data-disable-when-dependency-empty="true">
                    <option value=""></option>
                </x-forms.select>
            </div>

            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="{{ $reportId }}-city">{{ $report['filter_labels']['city_doc_num'] }}</label>
                <x-forms.select class="{{ $ajaxSelectClass }}" id="{{ $reportId }}-city" name="city_doc_num" data-filter-label="{{ $report['filter_labels']['city_doc_num'] }}" data-url="{{ route('admin.select2.cities') }}" data-placeholder="{{ __('business_partner_reports.placeholders.select_city') }}" data-allow-clear="true" data-depends-on="#{{ $reportId }}-governorate" data-dependent-param="governorate_doc_num" data-disable-when-dependency-empty="true">
                    <option value=""></option>
                </x-forms.select>
            </div>

            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="{{ $reportId }}-area">{{ $report['filter_labels']['area_doc_num'] }}</label>
                <x-forms.select class="{{ $ajaxSelectClass }}" id="{{ $reportId }}-area" name="area_doc_num" data-filter-label="{{ $report['filter_labels']['area_doc_num'] }}" data-url="{{ route('admin.select2.areas') }}" data-placeholder="{{ __('business_partner_reports.placeholders.select_area') }}" data-allow-clear="true" data-depends-on="#{{ $reportId }}-city" data-dependent-param="city_doc_num" data-disable-when-dependency-empty="true">
                    <option value=""></option>
                </x-forms.select>
            </div>

            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="{{ $reportId }}-data-completeness">{{ $report['filter_labels']['data_completeness'] }}</label>
                <x-forms.select class="{{ $staticSelectClass }}" id="{{ $reportId }}-data-completeness" name="data_completeness" data-filter-label="{{ $report['filter_labels']['data_completeness'] }}" data-placeholder="{{ __('reports.all_records') }}" data-allow-clear="true">
                    <option value=""></option>
                    @foreach (['complete', 'any_issue', 'missing_location', 'missing_address', 'missing_contact', 'legacy_unlinked_location'] as $completeness)
                        <option value="{{ $completeness }}">{{ __('business_partner_reports.completeness.'.$completeness) }}</option>
                    @endforeach
                </x-forms.select>
            </div>

            <div class="col-12">
                <div class="business-partner-filter-group-heading">
                    <span class="far fa-calendar-alt"></span>
                    <span>{{ __('business_partner_reports.filter_groups.dates') }}</span>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="{{ $reportId }}-created-from">{{ $report['filter_labels']['created_from'] }}</label>
                <div class="input-group input-group-sm w-100 report-date-input-group">
                    <x-forms.date-input class="form-control form-control-sm js-date-picker js-report-filter-control" id="{{ $reportId }}-created-from" name="created_from" type="text" data-filter-label="{{ $report['filter_labels']['created_from'] }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" />
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="{{ $reportId }}-created-to">{{ $report['filter_labels']['created_to'] }}</label>
                <div class="input-group input-group-sm w-100 report-date-input-group">
                    <x-forms.date-input class="form-control form-control-sm js-date-picker js-report-filter-control" id="{{ $reportId }}-created-to" name="created_to" type="text" data-filter-label="{{ $report['filter_labels']['created_to'] }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" />
                </div>
            </div>
        </x-admin.report.filter-panel>

        <x-admin.report.table-card
            :title="$report['table_title']"
            :table-id="$reportId.'-table'"
            :data-url="route($report['route_prefix'].'.data')"
        >
            <thead class="bg-100 text-900">
                <tr>
                    @foreach ($report['field_labels'] as $column => $label)
                        <th data-report-column="{{ $column }}">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
        </x-admin.report.table-card>
    </x-admin.report.page>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/modules/Core/report-ui.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/business-partner-data-report.js') }}"></script>
@endpush
