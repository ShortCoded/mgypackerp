@extends('layouts.app')

@php
    $isReport = $definition->kind() === 'report';
    $isPendingSurface = $screen['classification'] === 'UI_SURFACE_PENDING_DEEP_WORKFLOW';
    $columns = $screen['index_columns'];
    $canCreate = $definition->supportsMode('create') && auth()->user()?->can($definition->permission('create'));
    $createRoute = $canCreate && \Illuminate\Support\Facades\Route::has($definition->route('create'))
        ? route($definition->route('create'))
        : null;
    $filterTab = collect($screen['tabs'])->firstWhere('key', 'filters');
    $javascriptColumns = collect($columns)->map(static fn (array $column): array => [
        'data' => $column['data'],
        'name' => $column['data'],
        'orderable' => $column['orderable'],
        'searchable' => $column['searchable'],
        'defaultContent' => '',
        'className' => $column['protected'] ? 'no-colvis' : '',
    ])->values()->all();
    $erpUiShellConfig = [
        'mode' => 'index',
        'columns' => $javascriptColumns,
        'viewUrlTemplate' => $definition->supportsMode('view') ? route($definition->route('show'), ['doc_num' => '__DOC_NUM__']) : null,
        'editUrlTemplate' => $definition->supportsMode('edit') ? route($definition->route('edit'), ['doc_num' => '__DOC_NUM__']) : null,
        'messages' => __('erp_ui_shell.messages'),
    ];
@endphp

@section('title', $definition->title())

@push('styles')
    <link href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Core/erp-ui-shell.css') }}" rel="stylesheet">
@endpush

@section('content')
    @if ($isPendingSurface)
        <div class="card mb-3">
            <div class="card-header border-bottom border-200">
                <h5 class="mb-1">{{ $definition->title() }}</h5>
                <p class="mb-0 text-600">{{ __('erp_ui_shell.overview.description') }}</p>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach ($overview['metrics'] as $metric)
                        <div class="col-6 col-md-4 col-xl-3">
                            <div class="border rounded p-3 h-100 bg-light">
                                <div class="text-600 fs-11">{{ $metric['label'] }}</div>
                                <div class="fs-5 fw-semibold" dir="ltr">{{ number_format($metric['count']) }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($overview['links'] !== [])
                    <div class="d-flex flex-wrap gap-2 mt-4">
                        @foreach ($overview['links'] as $link)
                            <a class="btn btn-falcon-primary btn-sm" href="{{ $link['url'] }}">
                                {{ $link['label'] }}
                                <span class="fas fa-arrow-right ms-1"></span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($screen['show_document_number_settings'])
        @can($definition->permission('document_number_settings.update'))
            <div class="mb-3 card erp-ui-shell-settings-card">
                <div class="py-2 card-header">
                    <button class="p-0 btn btn-link text-decoration-none w-100 text-start d-flex align-items-center justify-content-between"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#erp-ui-shell-document-number-settings"
                        aria-expanded="false"
                        aria-controls="erp-ui-shell-document-number-settings">
                        <span class="fw-semibold">{{ __('erp_ui_shell.document_number_settings.title') }}</span>
                        <span class="fas fa-chevron-down fs-11"></span>
                    </button>
                </div>
                <div class="collapse" id="erp-ui-shell-document-number-settings">
                    <div class="py-3 card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-5 col-xl-4">
                                <label class="form-label" for="erp-ui-shell-prefix">{{ __('erp_ui_shell.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="erp-ui-shell-prefix" type="text" value="" placeholder="{{ __('erp_ui_shell.document_number_settings.prefix_placeholder') }}">
                            </div>
                            <div class="col-md-3 col-xl-2">
                                <label class="form-label" for="erp-ui-shell-padding">{{ __('erp_ui_shell.document_number_settings.padding') }}</label>
                                <input class="form-control" id="erp-ui-shell-padding" type="number" min="0" max="10" value="5" dir="ltr">
                            </div>
                            <div class="col-md-auto">
                                <button class="btn btn-falcon-primary js-erp-ui-operational-action" type="button">
                                    <span class="fas fa-save me-1"></span>{{ __('erp_ui_shell.actions.save_settings') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endcan
    @endif

    @if ($isReport && is_array($filterTab))
        <x-admin.report.page :title="$definition->title()" :description="__('erp_ui_shell.report.description')">
            <x-slot:actions>
                <div class="flex-wrap gap-2 d-flex">
                    {{-- <span class="badge badge-subtle-secondary align-self-center">{{ __('erp_ui_shell.ui_only') }}</span> --}}
                    <button class="btn btn-falcon-default btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#erp-ui-shell-report-filters" aria-expanded="true">
                        <span class="fas fa-filter me-1"></span>{{ __('erp_ui_shell.report.filters') }}
                    </button>
                    @can($definition->permission('print'))
                        <button class="btn btn-falcon-default btn-sm js-erp-ui-operational-action" type="button"><span class="fas fa-print me-1"></span>{{ __('erp_ui_shell.actions.print') }}</button>
                    @endcan
                    @can($definition->permission('export'))
                        <button class="btn btn-falcon-default btn-sm js-erp-ui-operational-action" type="button"><span class="fas fa-file-export me-1"></span>{{ __('erp_ui_shell.actions.export') }}</button>
                    @endcan
                </div>
            </x-slot:actions>

            <x-admin.report.filter-panel id="erp-ui-shell-report-filters" :title="__('erp_ui_shell.report.filters')">
                @foreach ($filterTab['sections'] ?? [] as $section)
                    @foreach ($section['fields'] ?? [] as $field)
                        @include('modules.ui-shell.partials.field', [
                            'field' => $field,
                            'mode' => 'create',
                            'isReadonly' => false,
                            'namePrefix' => 'filters',
                            'fieldIdPrefix' => 'report-filter',
                            'docNum' => null,
                        ])
                    @endforeach
                @endforeach
            </x-admin.report.filter-panel>
        </x-admin.report.page>
    @endif

    @unless ($isPendingSurface)
    <div class="card erp-datatable-card erp-ui-shell-index-card">
        <x-admin.crud-index-toolbar
            :title="$definition->title()"
            :add-route="$createRoute"
            :add-permission="$definition->permission('create')"
            :show-trash-filter="$screen['show_scope_filter'] && auth()->user()?->can($definition->permission('view_trashed'))"
            :show-bulk-actions="$screen['show_checkbox'] && auth()->user()?->can($definition->permission('delete'))"
            trash-filter-id="erp_ui_shell_scope_filter"
            bulk-actions-class="erp-ui-shell-bulk-actions"
            :bulk-action-label="__('erp_ui_shell.bulk_actions')"
            toolbar-actions-class="erp-ui-shell-toolbar-actions"
        >
            {{-- <span class="badge badge-subtle-secondary">{{ __('erp_ui_shell.ui_only') }}</span> --}}
        </x-admin.crud-index-toolbar>

        <div class="p-0 card-body">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table class="table mb-0 align-middle table-sm table-hover data-table erp-datatable w-100 js-erp-ui-shell-table"
                            id="erp-ui-shell-table"
                            data-url="{{ route($definition->route('data')) }}"
                            data-double-click-mode="{{ $screen['double_click_mode'] }}">
                            <thead class="bg-100 text-900">
                                <tr>
                                    @foreach ($columns as $column)
                                        <th @class([
                                            'text-900 pe-1 align-middle white-space-nowrap',
                                            'no-sort no-colvis all' => $column['protected'],
                                            'sort' => $column['orderable'],
                                            'text-center dt-select' => $column['data'] === 'select',
                                            'data-table-row-action dt-actions' => $column['data'] === 'actions',
                                            'dt-code' => $column['data'] === 'doc_num',
                                        ])>
                                            @if ($column['data'] === 'select')
                                                <div class="mb-0 form-check d-flex justify-content-center">
                                                    <input class="form-check-input" type="checkbox" aria-label="{{ __('erp_ui_shell.select_all') }}" disabled>
                                                </div>
                                            @else
                                                {{ $definition->localized($column['title']) }}
                                            @endif
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endunless
@endsection

@unless ($isPendingSurface)
@push('scripts')
    <script>
        window.ErpUiShellConfig = @json($erpUiShellConfig);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Core/erp-ui-shell.js') }}"></script>
@endpush
@endunless
