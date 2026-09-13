@extends('layouts.app')

@php
    $isFixedAssets = $module === 'fixed_assets';
    $routePrefix = $isFixedAssets
        ? 'admin.fixed-assets.assets.import'
        : 'admin.products.import';
    $indexRoute = $isFixedAssets
        ? 'admin.fixed-assets.assets.index'
        : 'admin.products.index';
    $fieldsBySheet = [$definition->dataSheet() => $definition->fields()];
    if (method_exists($definition, 'componentFields')) {
        $fieldsBySheet[$definition->componentSheet()] = $definition->componentFields();
    }
    $allFields = $definition->fields();
    foreach ($fieldsBySheet as $fields) {
        foreach ($fields as $key => $field) {
            $allFields[$key] ??= $field;
        }
    }
@endphp

@section('title', __('excel_imports.title'))

@section('content')
    @if(session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert-warning" role="alert">{{ session('warning') }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 text-center text-md-start">
                @foreach(['template', 'upload', 'review', 'confirm'] as $step)
                    <div class="col-6 col-md-3">
                        <div class="d-flex align-items-center gap-2 {{ $batch && $loop->index < 2 ? 'text-primary' : 'text-700' }}">
                            <span class="badge rounded-pill {{ $batch && $loop->index < 2 ? 'badge-subtle-primary' : 'badge-subtle-secondary' }}">{{ $loop->iteration }}</span>
                            <span class="fw-semibold">{{ __('excel_imports.steps.'.$step) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    @if(! $batch)
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title">{{ __('excel_imports.steps.template') }}</h5>
                        <p class="text-700">{{ __('excel_imports.template.title', ['module' => __('excel_imports.modules.'.$module)]) }}</p>
                        <a class="btn btn-falcon-primary" href="{{ route($routePrefix.'.template') }}">
                            <span class="fas fa-download me-1" aria-hidden="true"></span>{{ __('excel_imports.actions.download_template') }}
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title">{{ __('excel_imports.steps.upload') }}</h5>
                        <p class="text-700">{{ __('excel_imports.review.images_note') }}</p>
                        <form action="{{ route($routePrefix.'.store') }}" method="POST" enctype="multipart/form-data" data-excel-import-form>
                            @csrf
                            <label class="form-label" for="workbook">{{ __('excel_imports.actions.upload_validate') }}</label>
                            <div class="input-group">
                                <x-forms.input class="form-control @error('workbook') is-invalid @enderror" id="workbook" name="workbook" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
                                <button class="btn btn-falcon-primary" type="submit"><span class="fas fa-shield-alt me-1" aria-hidden="true"></span>{{ __('excel_imports.actions.upload_validate') }}</button>
                            </div>
                            @error('workbook')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h5 class="mb-0">{{ __('excel_imports.steps.review') }} — {{ __('excel_imports.modules.'.$module) }}</h5>
                <span class="badge rounded-pill {{ $batch->isReady() ? 'badge-subtle-success' : ($batch->status === 'imported' ? 'badge-subtle-primary' : 'badge-subtle-danger') }}">{{ __('excel_imports.review.status') }}: {{ __('excel_imports.statuses.'.$batch->status) }}</span>
            </div>
            <div class="card-body">
                <div class="row g-3 small">
                    <div class="col-md-4"><span class="text-700">{{ __('excel_imports.review.module') }}:</span> {{ __('excel_imports.modules.'.$module) }}</div>
                    <div class="col-md-4"><span class="text-700">{{ __('excel_imports.review.filename') }}:</span> {{ $batch->original_filename }}</div>
                    <div class="col-md-4"><span class="text-700">{{ __('excel_imports.review.uploaded_at') }}:</span> {{ $batch->created_at }}</div>
                    <div class="col-md-4"><span class="text-700">{{ __('excel_imports.review.template_version') }}:</span> {{ $batch->template_version }}</div>
                    <div class="col-md-8"><span class="text-700">{{ __('excel_imports.review.context') }}:</span> {{ $batch->context_snapshot['company_doc_num'] ?? '' }} / {{ $batch->context_snapshot['branch_doc_num'] ?? '' }} / {{ $batch->context_snapshot['financial_period_doc_num'] ?? '' }}</div>
                </div>
                <div class="row g-2 mt-3 text-center">
                    <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="text-700 small">{{ __('excel_imports.review.total_rows') }}</div><div class="fs-7 fw-bold">{{ $batch->total_rows }}</div></div></div>
                    <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="text-700 small">{{ __('excel_imports.review.valid_rows') }}</div><div class="fs-7 fw-bold text-success">{{ $batch->valid_rows }}</div></div></div>
                    <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="text-700 small">{{ __('excel_imports.review.error_rows') }}</div><div class="fs-7 fw-bold text-danger">{{ $batch->error_rows }}</div></div></div>
                    <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="text-700 small">{{ __('excel_imports.review.warnings') }}</div><div class="fs-7 fw-bold">{{ $batch->warning_count }}</div></div></div>
                </div>
            </div>
        </div>

        @if($batch->status === 'imported')
            <div class="card mb-3">
                <div class="card-body">
                    <h5>{{ __('excel_imports.review.results') }}</h5>
                    <ul class="mb-3">
                        @foreach($batch->result_summary ?? [] as $result)
                            <li>{{ $result['sheet_key'] }} #{{ $result['excel_row'] }} — {{ $result['doc_num'] }} / {{ $result['name'] }}</li>
                        @endforeach
                    </ul>
                    <a class="btn btn-falcon-primary" href="{{ route($indexRoute) }}">{{ __('excel_imports.actions.back_to_index') }}</a>
                </div>
            </div>
        @else
            <div class="alert {{ $batch->isReady() ? 'alert-success' : 'alert-danger' }}" role="alert">
                {{ $batch->isReady() ? __('excel_imports.review.ready_summary') : __('excel_imports.review.blocking_summary') }}
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-end">
                        <form class="d-flex flex-wrap gap-2" method="GET" action="{{ route($routePrefix.'.show', $batch->public_uuid) }}">
                            <x-forms.select class="form-select form-select-sm w-auto" name="filter" aria-label="{{ __('excel_imports.review.status') }}">
                                @foreach(['' => 'all', 'valid' => 'valid', 'errors' => 'errors', 'warnings' => 'warnings_filter'] as $value => $label)
                                    <option value="{{ $value }}" @selected((string) request('filter', '') === $value)>{{ __('excel_imports.review.'.$label) }}</option>
                                @endforeach
                            </x-forms.select>
                            <x-forms.input class="form-control form-control-sm" name="q" value="{{ request('q') }}" placeholder="{{ __('excel_imports.review.search') }}" />
                            <button class="btn btn-falcon-default btn-sm" type="submit">{{ __('common.search') }}</button>
                        </form>
                        <div class="d-flex flex-wrap gap-2">
                            @if($batch->error_rows > 0)
                                <a class="btn btn-falcon-danger btn-sm" href="{{ route($routePrefix.'.errors', $batch->public_uuid) }}">{{ __('excel_imports.actions.download_errors') }}</a>
                            @endif
                            <button class="btn btn-falcon-default btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#replace-workbook">{{ __('excel_imports.actions.replace_file') }}</button>
                            <form action="{{ route($routePrefix.'.cancel', $batch->public_uuid) }}" method="POST" data-excel-import-form>
                                @csrf
                                <button class="btn btn-falcon-danger btn-sm" type="submit">{{ __('excel_imports.actions.cancel') }}</button>
                            </form>
                        </div>
                    </div>
                    <div class="collapse mt-3" id="replace-workbook">
                        <form action="{{ route($routePrefix.'.replace', $batch->public_uuid) }}" method="POST" enctype="multipart/form-data" data-excel-import-form>
                            @csrf
                            <div class="input-group">
                                <x-forms.input class="form-control" name="workbook" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
                                <button class="btn btn-falcon-primary" type="submit">{{ __('excel_imports.actions.upload_validate') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body p-0">
                    <div class="erp-datatable-wrapper">
                        <div class="erp-datatable-scroll">
                            <table class="table table-sm table-hover mb-0 align-middle">
                                <thead class="bg-100 text-900">
                                    <tr>
                                        <th class="white-space-nowrap">{{ __('excel_imports.review.sheet') }}</th>
                                        <th class="white-space-nowrap">{{ __('excel_imports.review.excel_row') }}</th>
                                        <th class="white-space-nowrap">{{ __('excel_imports.review.status') }}</th>
                                        @foreach($allFields as $field)
                                            <th class="white-space-nowrap">{{ $field['label'] }}</th>
                                        @endforeach
                                        <th>{{ __('excel_imports.review.issues') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($rows as $row)
                                        @php($issuesByColumn = collect($row->issues ?? [])->groupBy('column'))
                                        <tr class="{{ $row->status === 'invalid' ? 'table-danger' : '' }}">
                                            <td class="white-space-nowrap">{{ $row->sheet_key }}</td>
                                            <td class="white-space-nowrap">{{ $row->excel_row }}</td>
                                            <td><span class="badge rounded-pill {{ $row->status === 'valid' ? 'badge-subtle-success' : 'badge-subtle-danger' }}">{{ __('excel_imports.statuses.'.$row->status) }}</span></td>
                                            @foreach($allFields as $key => $field)
                                                @php($cellIssues = $issuesByColumn->get($key, collect()))
                                                <td class="{{ $cellIssues->isNotEmpty() ? 'bg-danger-subtle' : '' }}" @if($cellIssues->isNotEmpty()) title="{{ $cellIssues->pluck('message')->implode(' | ') }}" @endif>
                                                    {{ $row->data[$key] ?? '' }}
                                                    @if($cellIssues->isNotEmpty())
                                                        <span class="visually-hidden">{{ $cellIssues->pluck('message')->implode(' ') }}</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td>
                                                @foreach($row->issues ?? [] as $issue)
                                                    <div class="small text-danger"><span class="fw-semibold">{{ $issue['column'] ?? __('excel_imports.review.status') }}:</span> {{ $issue['message'] }}</div>
                                                @endforeach
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="{{ count($allFields) + 4 }}" class="text-center text-700 py-4">{{ __('excel_imports.review.no_rows') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="card-footer">{{ $rows->links() }}</div>
            </div>

            <div class="card">
                <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <h5 class="mb-1">{{ __('excel_imports.steps.confirm') }}</h5>
                        <p class="mb-0 text-700">{{ __('excel_imports.review.images_note') }}</p>
                    </div>
                    <form action="{{ route($routePrefix.'.confirm', $batch->public_uuid) }}" method="POST" data-excel-import-form>
                        @csrf
                        <div class="form-check mb-2">
                            <x-forms.input class="form-check-input" id="confirm-import" name="confirmed" type="checkbox" value="1" :disabled='! $batch->isReady()' required />
                            <label class="form-check-label" for="confirm-import">{{ __('excel_imports.actions.confirm') }}</label>
                        </div>
                        <button class="btn btn-primary" type="submit" @disabled(! $batch->isReady())>{{ __('excel_imports.actions.confirm') }}</button>
                    </form>
                </div>
            </div>
        @endif
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/modules/Core/excel-imports.js') }}"></script>
@endpush
