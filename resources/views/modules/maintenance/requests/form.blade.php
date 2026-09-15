@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $title = $isView ? __('maintenance.requests.view_document', ['document' => $record->doc_num]) : ($isEdit ? __('maintenance.requests.edit_document', ['document' => $record->doc_num]) : __('maintenance.requests.create'));
    $maintainableKey = old('maintainable_key', $record?->fixed_asset_id ? 'asset:'.$record->fixed_asset_id : ($record?->production_mold_id ? 'mold:'.$record->production_mold_id : ''));
@endphp

@section('title', $title)

@section('content')
    <form data-maintenance-form method="POST" action="{{ $isEdit ? route('admin.maintenance.requests.update', $record) : route('admin.maintenance.requests.store') }}">
        @csrf
        @if($isEdit) @method('PUT') @endif
        <x-forms.input type="hidden" name="submit_action" value="save" />
        <div class="card mb-3">
            <div class="card-header py-2">
                <div class="row flex-between-center g-2"><div class="col"><h5 class="mb-0">{{ $title }}</h5></div><div class="col-auto">@include('modules.finance.partials.form-actions', ['mode' => $mode, 'record' => $record, 'resource' => 'maintenance.requests', 'routePrefix' => 'admin.maintenance.requests', 'canClone' => false, 'canEditRecord' => $record?->status === \Modules\Maintenance\Models\MaintenanceRequest::StatusOpen && ! $record?->workOrder, 'canDeleteRecord' => $record?->status === \Modules\Maintenance\Models\MaintenanceRequest::StatusOpen && ! $record?->workOrder])</div></div>
            </div>
            <div class="card-body">
                @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <fieldset @disabled($isView)>
                    <div class="row g-3 align-items-start">
                        <div class="col-lg-6">
                            <x-forms.label for="maintenance-request-maintainable" :label="__('maintenance.fields.maintainable')" required />
                            <x-forms.select variant="ajax" id="maintenance-request-maintainable" name="maintainable_key" :url="route('admin.maintenance.select2', ['lookup' => 'maintainables'])" :placeholder="__('maintenance.asset_or_mold')" required>
                                @foreach($maintainables as $maintainable)
                                    @php($key = $maintainable instanceof \Modules\FixedAssets\Models\FixedAsset ? 'asset:'.$maintainable->getKey() : 'mold:'.$maintainable->getKey())
                                    <option value="{{ $key }}" @selected($maintainableKey === $key)>{{ $maintainable instanceof \Modules\FixedAssets\Models\FixedAsset ? __('maintenance.maintainable_types.asset').' — '.$maintainable->doc_num.' — '.$maintainable->asset_name : __('maintenance.maintainable_types.mold').' — '.$maintainable->code.' — '.$maintainable->name }}</option>
                                @endforeach
                            </x-forms.select>
                        </div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-request-type" :label="__('maintenance.fields.request_type')" required /><x-forms.select variant="local" id="maintenance-request-type" name="request_type" :allow-clear="false" required>@foreach(['breakdown', 'inspection'] as $type)<option value="{{ $type }}" @selected(old('request_type', $record?->request_type ?? 'breakdown') === $type)>{{ __('maintenance.request_types.'.$type) }}</option>@endforeach</x-forms.select></div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-request-priority" :label="__('maintenance.fields.priority')" required /><x-forms.select variant="local" id="maintenance-request-priority" name="priority" :allow-clear="false" required>@foreach(['low', 'normal', 'high', 'urgent'] as $priority)<option value="{{ $priority }}" @selected(old('priority', $record?->priority ?? 'normal') === $priority)>{{ __('maintenance.priorities.'.$priority) }}</option>@endforeach</x-forms.select></div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-request-discipline" :label="__('maintenance.fields.discipline')" /><x-forms.select variant="local" id="maintenance-request-discipline" name="discipline"><option value="">—</option>@foreach(['electrical', 'mechanical', 'molds', 'other'] as $discipline)<option value="{{ $discipline }}" @selected(old('discipline', $record?->discipline) === $discipline)>{{ __('maintenance.disciplines.'.$discipline) }}</option>@endforeach</x-forms.select></div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-request-time" :label="__('maintenance.fields.reported_at')" /><x-forms.date-input id="maintenance-request-time" name="reported_at" :value="old('reported_at', $record?->reported_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i'))" enable-time /></div>
                        <div class="col-sm-6 col-lg-3 d-flex align-items-end"><div class="form-check mt-4"><x-forms.input class="form-check-input" type="checkbox" id="maintenance-request-stopped" name="is_machine_stopped" value="1" :checked="(bool) old('is_machine_stopped', $record?->is_machine_stopped)" /><x-forms.label class="form-check-label" for="maintenance-request-stopped" :label="__('maintenance.fields.is_machine_stopped')" /></div></div>
                        <div class="col-12"><x-forms.label for="maintenance-request-symptoms" :label="__('maintenance.fields.symptoms')" required /><x-forms.textarea id="maintenance-request-symptoms" name="symptoms" rows="4" maxlength="5000" required>{{ old('symptoms', $record?->symptoms) }}</x-forms.textarea></div>
                        <div class="col-12"><x-forms.label for="maintenance-request-notes" :label="__('maintenance.fields.notes')" /><x-forms.textarea id="maintenance-request-notes" name="notes" rows="3" maxlength="5000">{{ old('notes', $record?->notes) }}</x-forms.textarea></div>
                    </div>
                </fieldset>
            </div>
            <div class="card-footer">@include('modules.finance.partials.form-actions', ['mode' => $mode, 'record' => $record, 'resource' => 'maintenance.requests', 'routePrefix' => 'admin.maintenance.requests', 'canClone' => false, 'canEditRecord' => $record?->status === \Modules\Maintenance\Models\MaintenanceRequest::StatusOpen && ! $record?->workOrder, 'canDeleteRecord' => $record?->status === \Modules\Maintenance\Models\MaintenanceRequest::StatusOpen && ! $record?->workOrder])</div>
        </div>
    </form>
@endsection

@pushOnce('scripts', 'maintenance-execution-js')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endPushOnce
