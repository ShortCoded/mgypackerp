@extends('layouts.app')

@php
    $isEdit = $mode === 'edit';
    $title = $isEdit ? __('production_execution.quality.edit_document', ['document' => $record->doc_num]) : __('production_execution.quality.create_inspection');
    $subjectType = old('subject_type', $record?->subject_type ?? 'production_run');
@endphp

@section('title', $title)

@section('content')
    <div class="production-mobile-workflow">
    <form data-maintenance-form data-quality-request-form data-quality-balance-url="{{ route('admin.production.quality.stock-balance') }}" data-current-held="{{ $record?->stockHold?->base_quantity ?? 0 }}" data-original-product="{{ $record?->product_id }}" data-original-store="{{ $record?->branch_store_id }}" data-original-stock-status="{{ $record?->stock_status }}" data-original-batch="{{ $record?->batch_lot }}" method="POST" action="{{ $isEdit ? route('admin.production.quality.update', $record->getKey()) : route('admin.production.quality.store') }}">
        @csrf
        @if($isEdit) @method('PUT') @endif
        <x-forms.input type="hidden" name="submit_action" value="save" />
        <div class="card mb-3">
            <div class="card-header py-2"><div class="row flex-between-center g-2"><div class="col"><h5 class="mb-0">{{ $title }}</h5></div><div class="col-auto">@include('modules.finance.partials.form-actions', ['mode' => $mode, 'record' => $record, 'resource' => 'production.quality', 'routePrefix' => 'admin.production.quality', 'canClone' => false])</div></div></div>
            <div class="card-body">
                @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <div class="row g-3 align-items-start">
                    <div class="col-lg-6"><x-forms.label for="quality-subject-type" :label="__('production_execution.fields.inspection_subject')" required /><x-forms.select variant="local" id="quality-subject-type" name="subject_type" :allow-clear="false" required data-quality-subject-type>@foreach(['production_run', 'inventory_stock', 'product'] as $subject)<option value="{{ $subject }}" @selected($subjectType === $subject)>{{ __('production_execution.quality_subjects.'.$subject) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-lg-6" data-quality-subject-field="production_run"><x-forms.label for="quality-production-run" :label="__('production_execution.fields.run')" required /><x-forms.select variant="ajax" id="quality-production-run" name="production_run_id" :url="route('admin.production.quality.select2', ['lookup' => 'runs'])" :placeholder="__('common.actions.select')" data-quality-required>@foreach($runs as $run)<option value="{{ $run->getKey() }}" @selected((string) old('production_run_id', $record?->production_run_id ?? request('run')) === (string) $run->getKey())>{{ $run->run_number }} — {{ $run->product?->name ?? '—' }}@if($run->stageSnapshot) — {{ $run->stageSnapshot->stage_name }}@endif</option>@endforeach</x-forms.select></div>
                    <div class="col-lg-6" data-quality-subject-field="product,inventory_stock"><x-forms.label for="quality-product" :label="__('production_execution.fields.product')" required /><x-forms.select variant="ajax" id="quality-product" name="product_id" :url="route('admin.production.quality.select2', ['lookup' => 'products'])" :placeholder="__('common.actions.select')" data-quality-required>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) old('product_id', $record?->product_id) === (string) $product->id)>{{ $product->doc_num }} — {{ $product->name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-6 col-lg-4" data-quality-subject-field="inventory_stock"><x-forms.label for="quality-store" :label="__('production_execution.fields.store')" required /><x-forms.select variant="ajax" id="quality-store" name="branch_store_id" :url="route('admin.production.quality.select2', ['lookup' => 'stores'])" :placeholder="__('common.actions.select')" data-quality-required>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string) old('branch_store_id', $record?->branch_store_id) === (string) $store->id)>{{ $store->name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-6 col-lg-4" data-quality-subject-field="inventory_stock"><x-forms.label for="quality-stock-status" :label="__('production_execution.fields.stock_status')" required /><x-forms.select variant="local" id="quality-stock-status" name="stock_status" :allow-clear="false" data-quality-required>@foreach(['available', 'quarantine', 'rework', 'damaged', 'scrap'] as $status)<option value="{{ $status }}" @selected(old('stock_status', $record?->stock_status ?? 'available') === $status)>{{ __('production_execution.stock_statuses.'.$status) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-6 col-lg-4" data-quality-subject-field="inventory_stock"><x-forms.label for="quality-affected-quantity" :label="__('production_execution.fields.affected_quantity')" required /><x-forms.numeric-input id="quality-affected-quantity" name="affected_base_quantity" :value="old('affected_base_quantity', $record?->affected_base_quantity)" :scale="8" min="0.00000001" step="0.00000001" arrow-step="1" data-quality-required /></div>
                    <div class="col-md-6" data-quality-subject-field="inventory_stock"><x-forms.label for="quality-batch-lot" :label="__('production_execution.fields.batch_lot_meaningful')" /><x-forms.input id="quality-batch-lot" name="batch_lot" :value="old('batch_lot', $record?->batch_lot)" maxlength="120" /></div>
                    <div class="col-12" data-quality-subject-field="inventory_stock"><div class="alert alert-secondary mb-0" data-quality-stock-balance aria-live="polite">{{ __('production_execution.quality.stock_balance_waiting') }}</div></div>
                    <div class="col-md-6"><x-forms.label for="quality-inspection-type" :label="__('production_execution.fields.inspection_type_meaningful')" /><x-forms.select variant="ajax" id="quality-inspection-type" name="quality_inspection_type_id" :url="route('admin.production.quality.select2', ['lookup' => 'inspection-types'])" :placeholder="__('production_execution.quality.general_stage_inspection')"><option value="">{{ __('production_execution.quality.general_stage_inspection') }}</option>@foreach($inspectionTypes as $inspectionType)<option value="{{ $inspectionType->getKey() }}" @selected((string) old('quality_inspection_type_id', $record?->quality_inspection_type_id) === (string) $inspectionType->getKey())>{{ $inspectionType->code }} — {{ $inspectionType->name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-6"><x-forms.label for="quality-source-reference" :label="__('production_execution.fields.source_reference_meaningful')" /><x-forms.input id="quality-source-reference" name="source_reference" :value="old('source_reference', $record?->source_reference)" maxlength="255" /></div>
                    <div class="col-12"><x-forms.label for="quality-request-notes" :label="__('production_execution.fields.notes')" /><x-forms.textarea id="quality-request-notes" name="notes" rows="4" maxlength="5000">{{ old('notes', $record?->notes) }}</x-forms.textarea></div>
                </div>
            </div>
            <div class="card-footer">@include('modules.finance.partials.form-actions', ['mode' => $mode, 'record' => $record, 'resource' => 'production.quality', 'routePrefix' => 'admin.production.quality', 'canClone' => false])</div>
        </div>
    </form>
    </div>
@endsection

@pushOnce('styles', 'production-quality-css')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endPushOnce
@pushOnce('scripts', 'production-quality-js')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endPushOnce
