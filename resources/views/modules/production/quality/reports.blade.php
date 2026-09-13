@extends('layouts.app')

@section('title', __('production_execution.quality.daily_reports'))

@section('content')
    <div class="production-mobile-workflow">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
            <div><h4 class="mb-1">{{ __('production_execution.quality.daily_reports') }}</h4><p class="text-600 mb-0">{{ __('production_execution.quality.daily_reports_help') }}</p></div>
            <div class="d-flex flex-wrap gap-2"><a class="btn btn-falcon-default" href="{{ route('admin.production.quality.active') }}">{{ __('production_execution.quality.active_inspections') }}</a><a class="btn btn-falcon-default" href="{{ route('admin.production.quality.index') }}">{{ __('production_execution.quality.all_inspections') }}</a></div>
        </div>
        <div class="card erp-datatable-card">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-url="{{ route('admin.production.quality.reports.data') }}" data-order-column="1" data-order-direction="desc" data-columns='[{"data":"inspection_number","name":"inspection_number"},{"data":"reported_at","name":"reported_at"},{"data":"sequence","name":"sequence"},{"data":"subject","name":"subject"},{"data":"result","name":"result"},{"data":"observations","name":"observations"},{"data":"evidence_count","name":"evidence_count","searchable":false},{"data":"submitted_by_name","name":"submitted_by_name","defaultContent":"—"}]'>
                    <thead><tr><th>{{ __('production_execution.fields.inspection') }}</th><th>{{ __('production_execution.fields.reported_at') }}</th><th>{{ __('production_execution.fields.report_number') }}</th><th>{{ __('production_execution.fields.inspection_subject') }}</th><th>{{ __('production_execution.fields.result') }}</th><th>{{ __('production_execution.fields.observations') }}</th><th>{{ __('production_execution.fields.attachments') }}</th><th>{{ __('production_execution.fields.submitted_by') }}</th></tr></thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
