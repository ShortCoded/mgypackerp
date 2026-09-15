@extends('layouts.app')

@section('title', __('production_execution.quality.title'))

@section('content')
    <div class="production-mobile-workflow">
        <div class="alert alert-info py-2">{{ __('production_execution.quality.scope_help') }}</div>
        <div class="card erp-datatable-card" data-records-root data-bulk-delete-url="{{ route('admin.production.quality.bulk-delete') }}" data-bulk-param="ids">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col-auto"><h5 class="mb-0">{{ $scope === 'active' ? __('production_execution.quality.active_inspections') : __('production_execution.quality.all_inspections') }}</h5></div>
                    <div class="col-auto ms-auto d-flex flex-wrap align-items-center justify-content-end gap-2">
                        @can('production.quality.view_trashed')<x-forms.select class="form-select form-select-sm w-auto" data-trash-filter><option value="active">{{ __('maintenance.actions.active') }}</option><option value="trashed">{{ __('maintenance.actions.deleted') }}</option><option value="all">{{ __('maintenance.actions.all') }}</option></x-forms.select>@endcan
                        @can('production.quality.delete')<div class="d-none align-items-center gap-2" data-bulk-actions><span class="text-primary fw-semibold" data-selected-count>0</span><button class="btn btn-falcon-danger btn-sm" type="button" data-bulk-delete disabled><span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}</button></div>@endcan
                        <a class="btn btn-sm {{ $scope === 'active' ? 'btn-primary' : 'btn-falcon-default' }}" href="{{ route('admin.production.quality.active') }}">{{ __('production_execution.quality.active_inspections') }}</a>
                        <a class="btn btn-sm {{ $scope === 'all' ? 'btn-primary' : 'btn-falcon-default' }}" href="{{ route('admin.production.quality.index') }}">{{ __('production_execution.quality.all_inspections') }}</a>
                        <a class="btn btn-sm btn-falcon-default" href="{{ route('admin.production.quality.reports.index') }}">{{ __('production_execution.quality.reports_menu') }}</a>
                        @can('production.quality.create')<a class="btn btn-sm btn-primary" href="{{ route('admin.production.quality.create') }}"><span class="fas fa-plus me-1"></span>{{ __('production_execution.actions.create_inspection') }}</a>@endcan
                    </div>
                </div>
                <div class="row g-2 mt-2">
                    <div class="col-sm-4 col-lg-3"><x-forms.select class="form-select form-select-sm" name="subject_type" data-table-filter><option value="">{{ __('production_execution.quality.all_subjects') }}</option>@foreach(['production_run', 'inventory_stock', 'product'] as $subject)<option value="{{ $subject }}">{{ __('production_execution.quality_subjects.'.$subject) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-sm-4 col-lg-3"><x-forms.select class="form-select form-select-sm" name="status" data-table-filter><option value="">{{ __('production_execution.quality.all_statuses') }}</option>@foreach(['draft', 'received', 'in_progress', 'submitted', 'approved', 'rejected', 'closed'] as $status)<option value="{{ $status }}">{{ __('production_execution.statuses.'.$status) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-sm-4 col-lg-3"><x-forms.select class="form-select form-select-sm" name="result" data-table-filter><option value="">{{ __('production_execution.quality.all_results') }}</option>@foreach(['pending', 'passed', 'failed', 'conditional'] as $result)<option value="{{ $result }}">{{ __('production_execution.quality_results.'.$result) }}</option>@endforeach</x-forms.select></div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-record-selection data-url="{{ route('admin.production.quality.data', ['scope' => $scope]) }}" data-order-column="2" data-order-direction="desc" data-columns='[{"data":"select","name":"select","orderable":false,"searchable":false},{"data":"doc_num","name":"quality_inspections.doc_num"},{"data":"requested_at","name":"requested_at"},{"data":"subject","name":"subject"},{"data":"stage_name","name":"stage_name","defaultContent":"—"},{"data":"reports_count","name":"reports_count","searchable":false},{"data":"result","name":"result"},{"data":"disposition","name":"disposition"},{"data":"affected_base_quantity","name":"affected_base_quantity","defaultContent":"—"},{"data":"evidence_count","name":"evidence_count","searchable":false},{"data":"status","name":"status"},{"data":"actions","name":"actions","orderable":false,"searchable":false}]'>
                    <thead><tr><th class="dt-select"><x-forms.input class="form-check-input" type="checkbox" data-select-all /></th><th>{{ __('production_execution.fields.document') }}</th><th>{{ __('production_execution.fields.requested_at') }}</th><th>{{ __('production_execution.fields.inspection_subject') }}</th><th>{{ __('production_execution.fields.stage') }}</th><th>{{ __('production_execution.fields.reports_count') }}</th><th>{{ __('production_execution.fields.result') }}</th><th>{{ __('production_execution.fields.disposition') }}</th><th>{{ __('production_execution.fields.affected_quantity') }}</th><th>{{ __('production_execution.fields.attachments') }}</th><th>{{ __('production_execution.fields.status') }}</th><th></th></tr></thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@pushOnce('styles', 'production-quality-css')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endPushOnce
@pushOnce('scripts', 'production-quality-js')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endPushOnce
