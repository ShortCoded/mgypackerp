@extends('layouts.app')

@section('title', __('production_execution.stages.title'))

@section('content')
    <div class="production-mobile-workflow">
    <div class="card erp-datatable-card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <h5 class="mb-0">{{ __('production_execution.stages.title') }}</h5>
            <div class="d-flex flex-wrap gap-2">@can('production.stages.view_trashed')<div class="btn-group btn-group-sm"><a class="btn btn-falcon-default" href="{{ route('admin.production.stages.index') }}">{{ __('production_execution.actions.active') }}</a><a class="btn btn-falcon-default" href="{{ route('admin.production.stages.index', ['trash_filter' => 'trashed']) }}">{{ __('production_execution.actions.deleted') }}</a><a class="btn btn-falcon-default" href="{{ route('admin.production.stages.index', ['trash_filter' => 'all']) }}">{{ __('production_execution.actions.all') }}</a></div>@endcan @can('production.stages.create')<a class="btn btn-primary btn-sm" href="{{ route('admin.production.stages.create') }}">{{ __('production_execution.actions.add_stage') }}</a>@endcan</div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-url="{{ route('admin.production.stages.data', array_filter(['trash_filter' => request('trash_filter')])) }}" data-order-column="5" data-order-direction="asc" data-columns='[{"data":"code","name":"code"},{"data":"name","name":"name"},{"data":"output_type","name":"output_type","defaultContent":"—"},{"data":"duration","name":"duration"},{"data":"status","name":"status"},{"data":"display_order","name":"display_order"},{"data":"actions","name":"actions","orderable":false,"searchable":false}]'>
                <thead><tr><th>{{ __('production_execution.fields.code') }}</th><th>{{ __('production_execution.fields.name') }}</th><th>{{ __('production_execution.fields.output_type') }}</th><th>{{ __('production_execution.fields.standard_duration') }}</th><th>{{ __('production_execution.fields.status') }}</th><th>{{ __('production_execution.fields.order') }}</th><th></th></tr></thead>
            </table>
        </div>
    </div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>
@endpush
