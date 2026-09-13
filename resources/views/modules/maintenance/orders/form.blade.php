@extends('layouts.app')

@section('title', __('maintenance.orders.create'))

@section('content')
    <div class="production-mobile-workflow">
    @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('admin.maintenance.orders.store') }}" class="card">@csrf
        <div class="card-header"><h5 class="mb-0">{{ __('maintenance.orders.create') }}</h5></div>
        <div class="card-body"><div class="row g-3">
            @if ($requestRecord)<x-forms.input type="hidden" name="maintenance_request_doc_num" value="{{ $requestRecord->doc_num }}" /><div class="col-12"><div class="alert alert-info mb-0">{{ __('maintenance.fields.document') }}: {{ $requestRecord->doc_num }} — {{ $requestRecord->symptoms }}</div></div>@endif
            <div class="col-md-4"><label class="form-label">{{ __('maintenance.fields.asset') }}</label><x-forms.select class="form-select" name="fixed_asset_id" :required='!$requestRecord'>@if(!$requestRecord)<option value="">{{ __('common.placeholders.select') }}</option>@endif @foreach ($assets as $asset)<option value="{{ $asset->id }}" @selected(old('fixed_asset_id', $requestRecord?->fixed_asset_id) == $asset->id)>{{ $asset->asset_code }} — {{ $asset->asset_name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-4"><label class="form-label">{{ __('maintenance.fields.mold') }}</label><x-forms.select class="form-select" name="production_mold_id"><option value="">{{ __('maintenance.no_mold') }}</option>@foreach ($molds as $mold)<option value="{{ $mold->id }}" @selected(old('production_mold_id') == $mold->id)>{{ $mold->code }} — {{ $mold->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-2"><label class="form-label">{{ __('maintenance.fields.maintenance_type') }}</label><x-forms.select class="form-select" name="maintenance_type" required>@foreach (['preventive', 'corrective', 'emergency', 'external'] as $type)<option value="{{ $type }}">{{ __('maintenance.maintenance_types.'.$type) }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-2"><label class="form-label">{{ __('maintenance.fields.discipline') }}</label><x-forms.select class="form-select" name="discipline"><option value="">—</option>@foreach (['electrical', 'mechanical', 'molds', 'other'] as $discipline)<option value="{{ $discipline }}">{{ __('maintenance.disciplines.'.$discipline) }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-2"><label class="form-label">{{ __('maintenance.fields.priority') }}</label><x-forms.select class="form-select" name="priority">@foreach (['normal', 'high', 'urgent', 'low'] as $priority)<option value="{{ $priority }}">{{ __('maintenance.priorities.'.$priority) }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-2"><label class="form-label">{{ __('maintenance.fields.service_mode') }}</label><x-forms.select class="form-select" name="service_mode">@foreach (['internal', 'external'] as $mode)<option value="{{ $mode }}">{{ __('maintenance.service_modes.'.$mode) }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-4"><label class="form-label">{{ __('maintenance.fields.supplier') }}</label><x-forms.select class="form-select" name="supplier_id"><option value="">—</option>@foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-4"><label class="form-label">{{ __('maintenance.fields.external_provider_name') }}</label><x-forms.input class="form-control" name="external_provider_name" value="{{ old('external_provider_name') }}" /></div>
            <div class="col-md-4"><label class="form-label">{{ __('maintenance.fields.external_provider_contact') }}</label><x-forms.input class="form-control" name="external_provider_contact" value="{{ old('external_provider_contact') }}" /></div>
            <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.planned_start') }}</label><x-forms.date-input name="planned_start_at" enable-time /></div>
            <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.planned_end') }}</label><x-forms.date-input name="planned_end_at" enable-time /></div>
            <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.external_cost') }}</label><x-forms.input class="form-control" type="number" min="0" step="0.0001" name="external_cost" value="0" /></div>
            <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.next_due_date') }}</label><x-forms.date-input name="next_due_date" /></div>
            <div class="col-12"><label class="form-label">{{ __('maintenance.fields.work_description') }}</label><x-forms.textarea class="form-control" name="work_description" rows="4" required>{{ old('work_description', $requestRecord?->symptoms) }}</x-forms.textarea></div>
        </div></div>
        <div class="card-footer text-end"><button class="btn btn-primary">{{ __('maintenance.orders.create') }}</button></div>
    </form>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
