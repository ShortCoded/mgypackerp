@extends('layouts.app')

@php
    $isEdit = $record !== null && ! $isClone;
    $mode = $isClone ? 'clone' : ($isEdit ? 'edit' : 'create');
    $title = $isEdit
        ? __('production_execution.runs.edit_document', ['document' => $record->run_number])
        : __('production_execution.runs.'.$mode);
    $initialLabor = old('labor_details', $record?->labor_details ?: [[]]);
    $orderLinePublicId = old('production_order_line_public_id', $record?->orderLine?->public_id);
    $orderLineLabel = $record === null ? null : __('production_execution.runs.order_line_option', [
        'order' => $record->order?->doc_num,
        'line' => $record->orderLine?->line_number,
        'product' => $record->orderLine?->product?->name,
        'quantity' => app(\Modules\Core\Services\NumericFormatService::class)->format($record->orderLine?->quantity),
        'unit' => $record->orderLine?->unit?->name ?? '',
    ]);
    $stagePublicId = old('production_order_stage_snapshot_public_id', $record?->stageSnapshot?->public_id);
    $assetDocumentNumber = old('fixed_asset_doc_num', $record?->fixedAsset?->doc_num);
    $costCenterDocumentNumber = old('cost_center_doc_num', $record?->costCenter?->doc_num);
@endphp

@section('title', $title)

@section('content')
    <div class="production-mobile-workflow">
    <form data-production-run-plan-form method="POST" action="{{ $isEdit ? route('admin.production.runs.update', $record) : route('admin.production.runs.store') }}" novalidate>
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif
        <x-forms.input type="hidden" name="submit_action" value="save" />
        <x-forms.line-item-cards :line-label="__('production_execution.labor.worker_line')" />

        <div class="card mb-3">
            <div class="card-header py-2">
                <div class="row flex-between-center g-2">
                    <div class="col">
                        <h5 class="mb-0">{{ $title }}</h5>
                        @if($isClone)
                            <span class="badge badge-subtle-info mt-1">{{ __('production_execution.runs.copy_of', ['document' => $record->run_number]) }}</span>
                        @endif
                    </div>
                    <div class="col-auto">
                        @include('modules.finance.partials.form-actions', [
                            'mode' => $mode,
                            'record' => $record,
                            'resource' => 'production.runs',
                            'routePrefix' => 'admin.production.runs',
                        ])
                    </div>
                </div>
            </div>

            <div class="card-body">
                @if($errors->any())
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="row g-3 align-items-start">
                    <div class="col-lg-6">
                        <x-forms.label for="production-run-order-line" :label="__('production_execution.fields.order_line')" required />
                        @if($isEdit)
                            <x-forms.input id="production-run-order-line-display" :value="$orderLineLabel" readonly />
                            <x-forms.input id="production-run-order-line" type="hidden" name="production_order_line_public_id" :value="$orderLinePublicId" />
                        @else
                            <x-forms.select variant="ajax" id="production-run-order-line" name="production_order_line_public_id" :url="route('admin.production.runs.select2.order-lines')" :placeholder="__('production_execution.runs.select_order_line')" required>
                                @if($orderLinePublicId)<option value="{{ $orderLinePublicId }}" selected>{{ $orderLineLabel ?: $orderLinePublicId }}</option>@endif
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="production_order_line_public_id"></div>
                    </div>
                    <div class="col-lg-6">
                        <x-forms.label for="production-run-stage" :label="__('production_execution.fields.stage')" />
                        <x-forms.select variant="ajax" id="production-run-stage" name="production_order_stage_snapshot_public_id" :url="route('admin.production.runs.select2.stages')" data-depends-on="#production-run-order-line" data-dependent-param="production_order_line_public_id" data-disable-when-dependency-empty="true" :placeholder="__('production_execution.runs.select_stage')">
                            @if($stagePublicId)<option value="{{ $stagePublicId }}" selected>{{ $record?->stageSnapshot?->sequence }}. {{ $record?->stageSnapshot?->stage_name }}</option>@endif
                        </x-forms.select>
                        <div class="invalid-feedback d-block" data-error-for="production_order_stage_snapshot_public_id"></div>
                    </div>
                    <div class="col-md-4">
                        <x-forms.label for="production-run-planned-quantity" :label="__('production_execution.fields.planned_quantity')" required />
                        <x-forms.numeric-input id="production-run-planned-quantity" name="planned_quantity" :value="old('planned_quantity', $record?->planned_quantity)" :scale="8" step="0.00000001" arrow-step="1" min="0.00000001" required />
                        <div class="invalid-feedback d-block" data-error-for="planned_quantity"></div>
                    </div>
                    <div class="col-md-4">
                        <x-forms.label for="production-run-shift" :label="__('production_execution.fields.shift')" />
                        <x-forms.select variant="local" id="production-run-shift" name="production_shift_id" :placeholder="__('production_execution.runs.select_shift')">
                            <option value=""></option>
                            @foreach($shifts as $shift)
                                <option value="{{ $shift->id }}" @selected((string) old('production_shift_id', $record?->production_shift_id) === (string) $shift->id)>{{ $shift->code }} — {{ $shift->name }}</option>
                            @endforeach
                        </x-forms.select>
                        <div class="invalid-feedback d-block" data-error-for="production_shift_id"></div>
                    </div>
                    <div class="col-md-4">
                        <x-forms.label for="production-run-labor-count" :label="__('production_execution.fields.planned_labor_count')" />
                        <x-forms.numeric-input id="production-run-labor-count" name="planned_labor_count" :value="old('planned_labor_count', $record?->planned_labor_count)" :scale="0" step="1" arrow-step="1" min="0" />
                        <div class="invalid-feedback d-block" data-error-for="planned_labor_count"></div>
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="production-run-start" :label="__('production_execution.fields.starts_at')" required />
                        <x-forms.date-input id="production-run-start" name="planned_start_at" :value="old('planned_start_at', $record?->planned_start_at?->format('Y-m-d H:i'))" enable-time required />
                        <div class="invalid-feedback d-block" data-error-for="planned_start_at"></div>
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="production-run-end" :label="__('production_execution.fields.ends_at')" required />
                        <x-forms.date-input id="production-run-end" name="planned_end_at" :value="old('planned_end_at', $record?->planned_end_at?->format('Y-m-d H:i'))" enable-time required />
                        <div class="invalid-feedback d-block" data-error-for="planned_end_at"></div>
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="production-run-asset" :label="__('production_execution.fields.fixed_asset')" />
                        <x-forms.select variant="ajax" id="production-run-asset" name="fixed_asset_doc_num" :url="route('admin.production.runs.select2.assets')" :placeholder="__('production_execution.runs.select_asset')">
                            @if($assetDocumentNumber)<option value="{{ $assetDocumentNumber }}" selected>{{ $assetDocumentNumber }} — {{ $record?->fixedAsset?->asset_name }}</option>@endif
                        </x-forms.select>
                        <div class="invalid-feedback d-block" data-error-for="fixed_asset_doc_num"></div>
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="production-run-cost-center" :label="__('production_execution.fields.cost_center')" />
                        <x-forms.select variant="ajax" id="production-run-cost-center" name="cost_center_doc_num" :url="route('admin.production.runs.select2.cost-centers')" :placeholder="__('production_execution.runs.select_cost_center')">
                            @if($costCenterDocumentNumber)<option value="{{ $costCenterDocumentNumber }}" selected>{{ $costCenterDocumentNumber }} — {{ $record?->costCenter?->name }}</option>@endif
                        </x-forms.select>
                        <div class="invalid-feedback d-block" data-error-for="cost_center_doc_num"></div>
                    </div>
                    <div class="col-md-4">
                        <x-forms.label for="production-run-batch" :label="__('production_execution.fields.batch_lot')" />
                        <x-forms.input id="production-run-batch" name="batch_lot" :value="old('batch_lot', $record?->batch_lot)" maxlength="100" />
                    </div>
                    <div class="col-md-8">
                        <x-forms.label for="production-run-work-description" :label="__('production_execution.fields.work_description')" />
                        <x-forms.textarea id="production-run-work-description" name="work_description" rows="3" maxlength="5000">{{ old('work_description', $record?->work_description) }}</x-forms.textarea>
                        <div class="invalid-feedback d-block" data-error-for="work_description"></div>
                    </div>
                    <div class="col-12">
                        <x-forms.label for="production-run-notes" :label="__('production_execution.fields.notes')" />
                        <x-forms.textarea id="production-run-notes" name="notes" rows="3" maxlength="5000">{{ old('notes', $record?->notes) }}</x-forms.textarea>
                    </div>
                </div>

                <div class="border-top mt-4 pt-3" data-production-labor-planning>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <h6 class="text-700 mb-0">{{ __('production_execution.labor.planned_details') }}</h6>
                        <button class="btn btn-falcon-default btn-sm" type="button" data-add-labor-row title="{{ __('production_execution.labor.add_shortcut') }}" data-bs-title="{{ __('production_execution.labor.add_shortcut') }}"><span class="fas fa-plus me-1"></span>{{ __('production_execution.actions.add_worker') }}</button>
                    </div>
                    <div class="table-responsive" role="region" aria-label="{{ __('production_execution.labor.planned_details') }}" tabindex="0">
                        <table class="table table-sm table-hover align-middle mb-0 erp-entry-lines-table">
                            <thead class="bg-100 text-900"><tr><th class="text-center erp-entry-line-number">#</th><th class="erp-entry-line-item">{{ __('production_execution.fields.worker_name') }}</th><th class="erp-entry-line-text">{{ __('production_execution.fields.worker_role') }}</th><th class="erp-entry-line-quantity">{{ __('production_execution.fields.planned_hours') }}</th><th class="erp-entry-line-text">{{ __('production_execution.fields.notes') }}</th><th class="erp-entry-line-actions">{{ __('common.fields.actions') }}</th></tr></thead>
                            <tbody data-labor-rows></tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-end mt-2">
                        <button class="btn btn-falcon-default btn-sm" type="button" data-add-labor-row title="{{ __('production_execution.labor.add_shortcut') }}" data-bs-title="{{ __('production_execution.labor.add_shortcut') }}"><span class="fas fa-plus me-1"></span>{{ __('production_execution.actions.add_worker') }}</button>
                    </div>
                </div>
            </div>

            <div class="card-footer">
                @include('modules.finance.partials.form-actions', [
                    'mode' => $mode,
                    'record' => $record,
                    'resource' => 'production.runs',
                    'routePrefix' => 'admin.production.runs',
                ])
            </div>
        </div>
    </form>

    <template id="production-labor-row-template">
        <tr data-labor-row>
            <td class="text-center erp-entry-line-number" data-labor-row-number></td>
            <td class="erp-entry-line-item"><x-forms.select variant="ajax" name="labor_details[__INDEX__][employee_doc_num]" :url="route('admin.production.runs.select2.workers')" :placeholder="__('production_execution.runs.select_worker')" /></td>
            <td class="erp-entry-line-text"><x-forms.input name="labor_details[__INDEX__][role]" maxlength="255" /></td>
            <td class="erp-entry-line-quantity"><x-forms.numeric-input name="labor_details[__INDEX__][planned_hours]" :scale="2" min="0" step="0.25" arrow-step="1" /></td>
            <td class="erp-entry-line-text"><x-forms.input name="labor_details[__INDEX__][notes]" maxlength="1000" /></td>
            <td class="erp-entry-line-actions"><div class="d-flex gap-2 justify-content-center">
                <button class="btn btn-link text-600 p-0" type="button" data-duplicate-labor-row title="{{ __('production_execution.labor.duplicate_shortcut') }}" data-bs-title="{{ __('production_execution.labor.duplicate_shortcut') }}" aria-label="{{ __('production_execution.actions.duplicate_line') }}"><span class="fas fa-copy"></span></button>
                <button class="btn btn-link text-danger p-0" type="button" data-remove-labor-row title="{{ __('production_execution.labor.delete_shortcut') }}" data-bs-title="{{ __('production_execution.labor.delete_shortcut') }}" aria-label="{{ __('common.actions.delete') }}"><span class="fas fa-trash-alt"></span></button>
            </div></td>
        </tr>
    </template>
    <script type="application/json" data-production-labor-initial>{!! \Illuminate\Support\Js::encode($initialLabor) !!}</script>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">
@endpush

@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>
@endpush
