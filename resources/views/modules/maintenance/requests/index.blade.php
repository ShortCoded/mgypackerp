@extends('layouts.app')

@section('title', __('maintenance.requests.title'))

@section('content')
    <div class="production-mobile-workflow">
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    @can('maintenance.requests.create')
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">{{ __('maintenance.requests.create') }}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.maintenance.requests.store') }}" class="row g-3">
                    @csrf
                    <div class="col-md-4"><label class="form-label">{{ __('maintenance.fields.asset') }}</label><x-forms.select class="form-select" name="fixed_asset_id" required><option value="">{{ __('common.placeholders.select') }}</option>@foreach ($assets as $asset)<option value="{{ $asset->id }}">{{ $asset->asset_code }} — {{ $asset->asset_name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-2"><label class="form-label">{{ __('maintenance.fields.request_type') }}</label><x-forms.select class="form-select" name="request_type">@foreach (['breakdown', 'inspection'] as $type)<option value="{{ $type }}">{{ __('maintenance.request_types.'.$type) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-2"><label class="form-label">{{ __('maintenance.fields.discipline') }}</label><x-forms.select class="form-select" name="discipline"><option value="">—</option>@foreach (['electrical', 'mechanical', 'molds', 'other'] as $discipline)<option value="{{ $discipline }}">{{ __('maintenance.disciplines.'.$discipline) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-2"><label class="form-label">{{ __('maintenance.fields.priority') }}</label><x-forms.select class="form-select" name="priority">@foreach (['normal', 'high', 'urgent', 'low'] as $priority)<option value="{{ $priority }}">{{ __('maintenance.priorities.'.$priority) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-2"><label class="form-label">{{ __('maintenance.fields.reported_at') }}</label><x-forms.date-input name="reported_at" enable-time /></div>
                    <div class="col-12"><label class="form-label">{{ __('maintenance.fields.symptoms') }}</label><x-forms.textarea class="form-control" name="symptoms" rows="3" required>{{ old('symptoms') }}</x-forms.textarea></div>
                    <div class="col-12 text-end"><button class="btn btn-primary">{{ __('maintenance.requests.create') }}</button></div>
                </form>
            </div>
        </div>
    @endcan

    <div class="card erp-datatable-card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2"><h5 class="mb-0">{{ __('maintenance.requests.title') }}</h5>@can('maintenance.requests.view_trashed')<div class="btn-group btn-group-sm" role="group" aria-label="{{ __('maintenance.actions.record_filter') }}"><a class="btn btn-falcon-default" href="{{ route('admin.maintenance.requests.index') }}">{{ __('maintenance.actions.active') }}</a><a class="btn btn-falcon-default" href="{{ route('admin.maintenance.requests.index', ['trash_filter' => 'trashed']) }}">{{ __('maintenance.actions.deleted') }}</a><a class="btn btn-falcon-default" href="{{ route('admin.maintenance.requests.index', ['trash_filter' => 'all']) }}">{{ __('maintenance.actions.all') }}</a></div>@endcan</div>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-url="{{ route('admin.maintenance.requests.index', array_filter(['trash_filter' => request('trash_filter')])) }}" data-order-column="1" data-order-direction="desc" data-columns='[{"data":"doc_num","name":"maintenance_requests.doc_num"},{"data":"reported_at","name":"reported_at"},{"data":"asset_name","name":"fixed_assets.asset_name"},{"data":"request_type","name":"request_type"},{"data":"priority","name":"priority"},{"data":"symptoms","name":"symptoms"},{"data":"work_order_number","name":"maintenance_work_orders.doc_num","defaultContent":"—"},{"data":"status","name":"status"},{"data":"actions","name":"actions","orderable":false,"searchable":false}]'><thead><tr><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.reported_at') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.request_type') }}</th><th>{{ __('maintenance.fields.priority') }}</th><th>{{ __('maintenance.fields.symptoms') }}</th><th>{{ __('maintenance.fields.work_order') }}</th><th>{{ __('maintenance.fields.status') }}</th><th></th></tr></thead></table></div>
    </div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
