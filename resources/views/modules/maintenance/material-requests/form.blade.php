@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $title = $isView ? __('maintenance.material_requests.view_document', ['document' => $record->doc_num]) : ($isEdit ? __('maintenance.material_requests.edit_document', ['document' => $record->doc_num]) : __('maintenance.material_requests.create'));
    $recordLines = $record?->lines?->map(fn ($line) => ['product_id' => $line->product_id, 'item_type' => $line->item_type, 'quantity' => $line->requested_quantity, 'notes' => $line->notes])->values()->all() ?? [];
    $initialLines = old('lines', $recordLines ?: [['item_type' => 'spare_part']]);
@endphp

@section('title', $title)

@section('content')
    <form data-maintenance-form data-maintenance-material-form method="POST" action="{{ $isEdit ? route('admin.maintenance.material-requests.update', $record) : route('admin.maintenance.material-requests.store') }}">
        @csrf
        @if($isEdit) @method('PUT') @endif
        <x-forms.input type="hidden" name="submit_action" value="save" />
        <x-forms.line-item-cards :line-label="__('maintenance.material_requests.line')" />
        <div class="card mb-3">
            <div class="card-header py-2"><div class="row flex-between-center g-2"><div class="col"><h5 class="mb-0">{{ $title }}</h5></div><div class="col-auto">@include('modules.finance.partials.form-actions', ['mode' => $mode, 'record' => $record, 'resource' => 'maintenance.material_requests', 'routePrefix' => 'admin.maintenance.material-requests', 'canClone' => false, 'canEditRecord' => $record?->status === \Modules\Maintenance\Models\MaintenanceMaterialRequest::StatusSubmitted, 'canDeleteRecord' => $record?->status === \Modules\Maintenance\Models\MaintenanceMaterialRequest::StatusSubmitted])</div></div></div>
            <div class="card-body">
                @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <div class="alert alert-info py-2">{{ __('maintenance.material_requests.help') }}</div>
                <fieldset @disabled($isView)>
                    <div class="row g-3 align-items-start">
                        <div class="col-lg-6"><x-forms.label for="maintenance-material-order" :label="__('maintenance.fields.work_order')" required /><x-forms.select variant="local" id="maintenance-material-order" name="maintenance_work_order_id" required><option value="">{{ __('common.placeholders.select') }}</option>@foreach($orders as $order)<option value="{{ $order->id }}" @selected(old('maintenance_work_order_id', $record?->maintenance_work_order_id ?? request('order')) == $order->id)>{{ $order->doc_num }} — {{ $order->asset?->asset_name ?: $order->mold?->name }}</option>@endforeach</x-forms.select></div>
                        <div class="col-lg-6"><x-forms.label for="maintenance-material-store" :label="__('maintenance.fields.store')" required /><x-forms.select variant="local" id="maintenance-material-store" name="branch_store_id" required><option value="">{{ __('common.placeholders.select') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected(old('branch_store_id', $record?->branch_store_id) == $store->id)>{{ $store->name }}</option>@endforeach</x-forms.select></div>
                        <div class="col-md-6"><x-forms.label for="maintenance-material-reason" :label="__('maintenance.fields.reason')" /><x-forms.input id="maintenance-material-reason" name="reason" :value="old('reason', $record?->reason)" maxlength="2000" /></div>
                        <div class="col-md-6"><x-forms.label for="maintenance-material-notes" :label="__('maintenance.fields.notes')" /><x-forms.input id="maintenance-material-notes" name="notes" :value="old('notes', $record?->notes)" maxlength="5000" /></div>
                    </div>

                    <div class="border-top mt-4 pt-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2"><h6 class="text-700 mb-0">{{ __('maintenance.fields.items') }}</h6><button class="btn btn-falcon-default btn-sm" type="button" data-add-maintenance-material><span class="fas fa-plus me-1"></span>{{ __('maintenance.actions.add_item') }}</button></div>
                        <div class="table-responsive" role="region" aria-label="{{ __('maintenance.fields.items') }}" tabindex="0">
                            <table class="table table-sm table-hover align-middle mb-0 erp-entry-lines-table">
                                <thead class="bg-100 text-900"><tr><th class="text-center erp-entry-line-number">#</th><th class="erp-entry-line-item">{{ __('maintenance.fields.item') }} <span class="text-danger">*</span></th><th>{{ __('maintenance.fields.item_type') }} <span class="text-danger">*</span></th><th class="erp-entry-line-quantity">{{ __('maintenance.fields.quantity') }} <span class="text-danger">*</span></th><th>{{ __('maintenance.fields.notes') }}</th><th class="erp-entry-line-actions">{{ __('common.fields.actions') }}</th></tr></thead>
                                <tbody data-maintenance-material-lines></tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-end mt-2"><button class="btn btn-falcon-default btn-sm" type="button" data-add-maintenance-material><span class="fas fa-plus me-1"></span>{{ __('maintenance.actions.add_item') }}</button></div>
                    </div>
                </fieldset>
            </div>
            <div class="card-footer">@include('modules.finance.partials.form-actions', ['mode' => $mode, 'record' => $record, 'resource' => 'maintenance.material_requests', 'routePrefix' => 'admin.maintenance.material-requests', 'canClone' => false, 'canEditRecord' => $record?->status === \Modules\Maintenance\Models\MaintenanceMaterialRequest::StatusSubmitted, 'canDeleteRecord' => $record?->status === \Modules\Maintenance\Models\MaintenanceMaterialRequest::StatusSubmitted])</div>
        </div>
    </form>

    <template id="maintenance-material-line-template">
        <tr data-maintenance-material-row>
            <td class="text-center erp-entry-line-number" data-row-number></td>
            <td class="erp-entry-line-item"><x-forms.select variant="local" name="lines[__INDEX__][product_id]" required><option value="">{{ __('common.placeholders.select') }}</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->doc_num }} — {{ $product->name }}</option>@endforeach</x-forms.select></td>
            <td><x-forms.select variant="local" name="lines[__INDEX__][item_type]" :allow-clear="false" required>@foreach(['spare_part', 'oil', 'consumable'] as $type)<option value="{{ $type }}">{{ __('maintenance.item_types.'.$type) }}</option>@endforeach</x-forms.select></td>
            <td class="erp-entry-line-quantity"><x-forms.numeric-input name="lines[__INDEX__][quantity]" :scale="8" min="0.00000001" step="0.00000001" arrow-step="1" required /></td>
            <td><x-forms.input name="lines[__INDEX__][notes]" maxlength="1000" /></td>
            <td class="erp-entry-line-actions"><div class="d-flex gap-2 justify-content-center"><button class="btn btn-link text-600 p-0" type="button" data-duplicate-maintenance-material title="{{ __('maintenance.actions.duplicate_item') }}"><span class="fas fa-copy"></span></button><button class="btn btn-link text-danger p-0" type="button" data-remove-maintenance-material title="{{ __('maintenance.actions.remove_item') }}"><span class="fas fa-trash-alt"></span></button></div></td>
        </tr>
    </template>
    <script type="application/json" data-maintenance-material-initial-lines>{!! \Illuminate\Support\Js::encode($initialLines) !!}</script>
@endsection

@pushOnce('styles', 'maintenance-execution-css')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endPushOnce
@pushOnce('scripts', 'maintenance-execution-js')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endPushOnce
