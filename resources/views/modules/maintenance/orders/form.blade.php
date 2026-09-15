@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $title = $isEdit ? __('maintenance.orders.edit_document', ['document' => $record->doc_num]) : __('maintenance.orders.create');
    $maintainableKey = old('maintainable_key', $requestRecord?->fixed_asset_id ? 'asset:'.$requestRecord->fixed_asset_id : ($requestRecord?->production_mold_id ? 'mold:'.$requestRecord->production_mold_id : ($record?->fixed_asset_id ? 'asset:'.$record->fixed_asset_id : ($record?->production_mold_id ? 'mold:'.$record->production_mold_id : ''))));
    $serviceMode = old('service_mode', $record?->service_mode ?? 'internal');
@endphp

@section('title', $title)

@section('content')
    <div class="production-mobile-workflow">
    <form data-maintenance-form data-maintenance-order-form method="POST" action="{{ $isEdit ? route('admin.maintenance.orders.update', $record) : route('admin.maintenance.orders.store') }}">
        @csrf
        @if($isEdit) @method('PUT') @endif
        <x-forms.input type="hidden" name="submit_action" value="save" />
        @if($requestRecord)<x-forms.input type="hidden" name="maintenance_request_doc_num" :value="$requestRecord->doc_num" />@endif
        <div class="card mb-3">
            <div class="card-header py-2"><div class="row flex-between-center g-2"><div class="col"><h5 class="mb-0">{{ $title }}</h5></div><div class="col-auto">@include('modules.finance.partials.form-actions', ['mode' => $mode, 'record' => $record, 'resource' => 'maintenance.orders', 'routePrefix' => 'admin.maintenance.orders', 'canClone' => false])</div></div></div>
            <div class="card-body">
                @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                @if($requestRecord)<div class="alert alert-info">{{ __('maintenance.fields.source_report') }}: <strong>{{ $requestRecord->doc_num }}</strong> — {{ $requestRecord->symptoms }}</div>@endif
                <fieldset @disabled($isView)>
                    <div class="row g-3 align-items-start">
                        <div class="col-lg-6"><x-forms.label for="maintenance-order-maintainable" :label="__('maintenance.fields.maintainable')" required /><x-forms.select variant="ajax" id="maintenance-order-maintainable" name="maintainable_key" :url="route('admin.maintenance.select2', ['lookup' => 'maintainables'])" :placeholder="__('maintenance.asset_or_mold')" :disabled="$requestRecord !== null" required>@foreach($maintainables as $maintainable)@php($key = $maintainable instanceof \Modules\FixedAssets\Models\FixedAsset ? 'asset:'.$maintainable->getKey() : 'mold:'.$maintainable->getKey())<option value="{{ $key }}" @selected($maintainableKey === $key)>{{ $maintainable instanceof \Modules\FixedAssets\Models\FixedAsset ? __('maintenance.maintainable_types.asset').' — '.$maintainable->doc_num.' — '.$maintainable->asset_name : __('maintenance.maintainable_types.mold').' — '.$maintainable->code.' — '.$maintainable->name }}</option>@endforeach</x-forms.select>@if($requestRecord)<x-forms.input type="hidden" name="maintainable_key" :value="$maintainableKey" />@endif</div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-order-type" :label="__('maintenance.fields.maintenance_type')" required /><x-forms.select variant="local" id="maintenance-order-type" name="maintenance_type" :allow-clear="false" required>@foreach(['preventive', 'corrective', 'emergency', 'condition_based'] as $type)<option value="{{ $type }}" @selected(old('maintenance_type', $record?->maintenance_type ?? ($requestRecord ? 'corrective' : 'preventive')) === $type)>{{ __('maintenance.maintenance_types.'.$type) }}</option>@endforeach</x-forms.select></div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-order-priority" :label="__('maintenance.fields.priority')" required /><x-forms.select variant="local" id="maintenance-order-priority" name="priority" :allow-clear="false" required>@foreach(['low', 'normal', 'high', 'urgent'] as $priority)<option value="{{ $priority }}" @selected(old('priority', $record?->priority ?? $requestRecord?->priority ?? 'normal') === $priority)>{{ __('maintenance.priorities.'.$priority) }}</option>@endforeach</x-forms.select></div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-order-discipline" :label="__('maintenance.fields.discipline')" /><x-forms.select variant="local" id="maintenance-order-discipline" name="discipline"><option value="">—</option>@foreach(['electrical', 'mechanical', 'molds', 'other'] as $discipline)<option value="{{ $discipline }}" @selected(old('discipline', $record?->discipline ?? $requestRecord?->discipline) === $discipline)>{{ __('maintenance.disciplines.'.$discipline) }}</option>@endforeach</x-forms.select></div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-order-mode" :label="__('maintenance.fields.service_mode')" required /><x-forms.select variant="local" id="maintenance-order-mode" name="service_mode" :allow-clear="false" required>@foreach(['internal', 'external', 'mixed'] as $value)<option value="{{ $value }}" @selected($serviceMode === $value)>{{ __('maintenance.service_modes.'.$value) }}</option>@endforeach</x-forms.select></div>
                        <div class="col-lg-6" data-external-maintenance-fields @if($serviceMode === 'internal') hidden @endif><div class="row g-3"><div class="col-md-6"><x-forms.label for="maintenance-order-supplier" :label="__('maintenance.fields.supplier')" /><x-forms.select variant="ajax" id="maintenance-order-supplier" name="supplier_id" :url="route('admin.maintenance.select2', ['lookup' => 'suppliers'])" placeholder="—"><option value="">—</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('supplier_id', $record?->supplier_id) == $supplier->id)>{{ $supplier->doc_num }} — {{ $supplier->name }}</option>@endforeach</x-forms.select></div><div class="col-md-6"><x-forms.label for="maintenance-provider-name" :label="__('maintenance.fields.external_provider_name')" /><x-forms.input id="maintenance-provider-name" name="external_provider_name" :value="old('external_provider_name', $record?->external_provider_name)" maxlength="255" /></div><div class="col-12"><x-forms.label for="maintenance-provider-contact" :label="__('maintenance.fields.external_provider_contact')" /><x-forms.input id="maintenance-provider-contact" name="external_provider_contact" :value="old('external_provider_contact', $record?->external_provider_contact)" maxlength="255" /></div></div><div class="form-text">{{ __('maintenance.messages.external_provider_help') }}</div></div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-planned-start" :label="__('maintenance.fields.planned_start')" /><x-forms.date-input id="maintenance-planned-start" name="planned_start_at" :value="old('planned_start_at', $record?->planned_start_at?->format('Y-m-d H:i'))" enable-time /></div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-planned-end" :label="__('maintenance.fields.planned_end')" /><x-forms.date-input id="maintenance-planned-end" name="planned_end_at" :value="old('planned_end_at', $record?->planned_end_at?->format('Y-m-d H:i'))" enable-time /></div>
                        <div class="col-sm-6 col-lg-3" data-external-maintenance-fields @if($serviceMode === 'internal') hidden @endif><x-forms.label for="maintenance-external-cost" :label="__('maintenance.fields.external_cost')" /><x-forms.numeric-input id="maintenance-external-cost" name="external_cost" :value="old('external_cost', $record?->external_cost ?? 0)" :scale="4" min="0" step="0.0001" /></div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-next-due" :label="__('maintenance.fields.next_due_date')" /><x-forms.date-input id="maintenance-next-due" name="next_due_date" :value="old('next_due_date', $record?->next_due_date?->toDateString())" /></div>
                        <div class="col-12"><x-forms.label for="maintenance-work-description" :label="__('maintenance.fields.work_description')" required /><x-forms.textarea id="maintenance-work-description" name="work_description" rows="4" maxlength="5000" required>{{ old('work_description', $record?->work_description ?? $requestRecord?->symptoms) }}</x-forms.textarea></div>
                    </div>
                </fieldset>
            </div>
            <div class="card-footer">@include('modules.finance.partials.form-actions', ['mode' => $mode, 'record' => $record, 'resource' => 'maintenance.orders', 'routePrefix' => 'admin.maintenance.orders', 'canClone' => false])</div>
        </div>
    </form>
    </div>
@endsection

@pushOnce('styles', 'maintenance-execution-css')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endPushOnce
@pushOnce('scripts', 'maintenance-execution-js')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endPushOnce
