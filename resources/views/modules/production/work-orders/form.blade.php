@extends('layouts.app')

@php
    $isEdit = $record !== null && ! $isClone;
    $mode = $isClone ? 'clone' : ($isEdit ? 'edit' : 'create');
    $title = $isEdit
        ? __('production_execution.orders.edit_document', ['document' => $record->doc_num])
        : __('production_execution.orders.'.$mode);
    $recordLines = $record?->lines?->map(fn ($line) => [
        'source_line_reference' => $sourceLineReferences->get($line->getKey()),
        'product_text' => trim(($line->product?->doc_num ?? '').' — '.($line->product?->name ?? '')),
        'quantity' => $line->quantity,
        'required_quantity' => $record?->source_type === 'make_to_stock' ? null : $line->quantity,
        'description' => $line->description,
        'production_notes' => $line->production_notes,
        'stages' => $line->stageSnapshots->map(fn ($stage) => [
            'id' => $stage->productStage?->public_id,
            'text' => $stage->sequence.'. '.$stage->stage_name,
        ])->filter(fn ($stage) => filled($stage['id']))->values()->all(),
    ])->values()->all() ?? [];
    $initialOrderStages = $record?->orderStageSnapshots?->map(fn ($stage) => [
        'id' => $stage->stage?->public_id,
        'text' => $stage->sequence.'. '.$stage->stage_name,
    ])->filter(fn ($stage) => filled($stage['id']))->values()->all() ?? [];
    $initialLines = old('lines', $recordLines ?: [[]]);
    $sourceType = old('source_type', $record?->source_type ?? 'make_to_stock');
@endphp

@section('title', $title)

@section('content')
    <div class="production-mobile-workflow">
    <form data-production-order-form data-line-details-url="{{ route('admin.production.work-orders.select2.line-details') }}" method="POST" action="{{ $isEdit ? route('admin.production.work-orders.update', $record) : route('admin.production.work-orders.store') }}" novalidate>
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif
        <x-forms.line-item-cards :line-label="__('production_execution.orders.line')" />
        <x-forms.input type="hidden" name="submit_action" value="save" />

        <div class="card mb-3">
            <div class="card-header py-2">
                <div class="row flex-between-center g-2">
                    <div class="col">
                        <h5 class="mb-0">{{ $title }}</h5>
                        @if($isClone)
                            <span class="badge badge-subtle-info mt-1">{{ __('production_execution.orders.copy_of', ['document' => $record->doc_num]) }}</span>
                        @endif
                    </div>
                    <div class="col-auto">
                        @include('modules.finance.partials.form-actions', [
                            'mode' => $mode,
                            'record' => $record,
                            'resource' => 'production.orders',
                            'routePrefix' => 'admin.production.work-orders',
                        ])
                    </div>
                </div>
            </div>

            <div class="card-body">
                @if ($errors->any())
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <h6 class="text-700 mb-3">{{ __('production_execution.orders.header_data') }}</h6>
                <div class="row g-3 align-items-start">
                    <div class="col-md-4">
                        <x-forms.label for="production-source-type" :label="__('production_execution.fields.source')" required />
                        <x-forms.select variant="local" id="production-source-type" name="source_type" :allow-clear="false" required>
                            @foreach(['make_to_stock', 'sales_order', 'customer_invoice'] as $value)
                                <option value="{{ $value }}" @selected($sourceType === $value)>{{ __('production_execution.source_types.'.$value) }}</option>
                            @endforeach
                        </x-forms.select>
                        <div class="invalid-feedback d-block" data-error-for="source_type"></div>
                    </div>
                    <div class="col-md-4" data-production-source-document @if($sourceType === 'make_to_stock') hidden @endif>
                        <x-forms.label for="production-source-document" :label="__('production_execution.fields.source_document')" required />
                        <x-forms.select variant="ajax" id="production-source-document" name="source_doc_num" :url="route('admin.production.work-orders.select2.sources')" data-depends-on="#production-source-type" data-dependent-param="source_type" :placeholder="__('production_execution.orders.select_source_document')">
                            @if(old('source_doc_num', $sourceDocumentNumber))
                                <option value="{{ old('source_doc_num', $sourceDocumentNumber) }}" selected>{{ old('source_doc_num', $sourceDocumentNumber) }}</option>
                            @endif
                        </x-forms.select>
                        <div class="invalid-feedback d-block" data-error-for="source_doc_num"></div>
                    </div>
                    <div class="col-md-4">
                        <x-forms.label for="production-order-date" :label="__('production_execution.fields.order_date')" required />
                        <x-forms.date-input id="production-order-date" name="production_order_date" :value="old('production_order_date', $record?->production_order_date?->toDateString() ?? now()->toDateString())" required />
                        <div class="invalid-feedback d-block" data-error-for="production_order_date"></div>
                    </div>
                    <div class="col-md-3">
                        <x-forms.label for="production-expected-start-date" :label="__('production_execution.fields.expected_start_date')" />
                        <x-forms.date-input id="production-expected-start-date" name="expected_start_date" :value="old('expected_start_date', $record?->expected_start_date?->toDateString())" />
                        <div class="invalid-feedback d-block" data-error-for="expected_start_date"></div>
                    </div>
                    <div class="col-md-3">
                        <x-forms.label for="production-expected-finish-date" :label="__('production_execution.fields.expected_finish_date')" />
                        <x-forms.date-input id="production-expected-finish-date" name="expected_finish_date" :value="old('expected_finish_date', $record?->expected_finish_date?->toDateString())" />
                        <div class="invalid-feedback d-block" data-error-for="expected_finish_date"></div>
                    </div>
                    <div class="col-md-3">
                        <x-forms.label for="production-delivery-date" :label="__('production_execution.fields.delivery_date')" />
                        <x-forms.date-input id="production-delivery-date" name="expected_delivery_date" :value="old('expected_delivery_date', $record?->expected_delivery_date?->toDateString())" />
                        <div class="invalid-feedback d-block" data-error-for="expected_delivery_date"></div>
                    </div>
                    <div class="col-md-3">
                        <x-forms.label for="production-priority" :label="__('production_execution.fields.priority')" />
                        <x-forms.select variant="local" id="production-priority" name="priority" :allow-clear="false">
                            @foreach(['low', 'normal', 'high', 'urgent'] as $priority)
                                <option value="{{ $priority }}" @selected(old('priority', $record?->priority ?? 'normal') === $priority)>{{ __('production_execution.priorities.'.$priority) }}</option>
                            @endforeach
                        </x-forms.select>
                        <div class="invalid-feedback d-block" data-error-for="priority"></div>
                    </div>
                    <div class="col-md-3">
                        <x-forms.label for="production-overproduction-tolerance" :label="__('production_execution.fields.overproduction_tolerance_percent')" />
                        <x-forms.numeric-input id="production-overproduction-tolerance" name="overproduction_tolerance_percent" :value="old('overproduction_tolerance_percent', $record?->overproduction_tolerance_percent ?? 0)" :scale="4" min="0" max="100" step="0.0001" arrow-step="1" />
                        <div class="invalid-feedback d-block" data-error-for="overproduction_tolerance_percent"></div>
                    </div>
                    <div class="col-md-9">
                        <x-forms.label for="production-notes" :label="__('production_execution.fields.production_notes')" />
                        <x-forms.textarea id="production-notes" name="production_notes" rows="3" maxlength="5000">{{ old('production_notes', $record?->production_notes) }}</x-forms.textarea>
                        <div class="invalid-feedback d-block" data-error-for="production_notes"></div>
                    </div>
                </div>

                <div class="border-top mt-4 pt-3">
                    <div class="mb-3">
                        <x-forms.label for="production-order-route" :label="__('production_execution.orders.order_route')" />
                        <x-forms.select variant="ajax" id="production-order-route" name="order_stage_public_ids[]" :url="route('admin.production.work-orders.select2.order-stages')" :placeholder="__('production_execution.orders.select_order_stages')" multiple>
                            @foreach($initialOrderStages as $stage)
                                <option value="{{ $stage['id'] }}" selected>{{ $stage['text'] }}</option>
                            @endforeach
                        </x-forms.select>
                        <div class="form-text">{{ __('production_execution.orders.order_route_help') }}</div>
                        <div class="invalid-feedback d-block" data-error-for="order_stage_public_ids"></div>
                    </div>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <h6 class="text-700 mb-0">{{ __('production_execution.orders.finished_products') }}</h6>
                        <button class="btn btn-falcon-default btn-sm" type="button" data-add-production-line title="{{ __('production_execution.orders.add_line_shortcut') }}" data-bs-title="{{ __('production_execution.orders.add_line_shortcut') }}">
                            <span class="fas fa-plus me-1"></span>{{ __('production_execution.actions.add_line') }}
                        </button>
                    </div>
                    <div class="table-responsive" role="region" aria-label="{{ __('production_execution.orders.finished_products') }}" tabindex="0">
                        <table class="table table-sm table-hover align-middle mb-0 erp-entry-lines-table">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-center erp-entry-line-number">#</th>
                                    <th class="erp-entry-line-item">{{ __('production_execution.fields.source_line_product') }}</th>
                                    <th class="erp-entry-line-quantity">{{ __('production_execution.fields.quantity') }} / {{ __('production_execution.fields.unit') }}</th>
                                    <th class="erp-entry-line-text">{{ __('production_execution.fields.description') }}</th>
                                    <th class="erp-entry-line-text">{{ __('production_execution.fields.production_route') }}</th>
                                    <th class="erp-entry-line-text">{{ __('production_execution.fields.notes') }}</th>
                                    <th class="erp-entry-line-actions">{{ __('common.fields.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody data-production-order-lines></tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-end mt-2">
                        <button class="btn btn-falcon-default btn-sm" type="button" data-add-production-line title="{{ __('production_execution.orders.add_line_shortcut') }}" data-bs-title="{{ __('production_execution.orders.add_line_shortcut') }}">
                            <span class="fas fa-plus me-1"></span>{{ __('production_execution.actions.add_line') }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="card-footer">
                @include('modules.finance.partials.form-actions', [
                    'mode' => $mode,
                    'record' => $record,
                    'resource' => 'production.orders',
                    'routePrefix' => 'admin.production.work-orders',
                ])
            </div>
        </div>
    </form>

    <template id="production-order-line-template">
        <tr data-production-order-line>
            <td class="text-center erp-entry-line-number" data-row-number></td>
            <td class="erp-entry-line-item">
                <x-forms.select variant="ajax" name="lines[__INDEX__][source_line_reference]" :url="route('admin.production.work-orders.select2.products')" :placeholder="__('production_execution.orders.select_product')" :data-extra-params="json_encode(['source_type' => '#production-source-type', 'source_doc_num' => '#production-source-document'])" required />
            </td>
            <td class="erp-entry-line-quantity">
                <x-forms.numeric-input name="lines[__INDEX__][quantity]" :scale="8" min="0.00000001" step="0.00000001" arrow-step="1" required />
                <div class="small text-600 mt-1" data-line-unit-details></div>
                <div class="small fw-semibold text-primary mt-1" data-line-equivalent-output></div>
                <div class="small mt-2 d-none" data-line-component-preview></div>
            </td>
            <td class="erp-entry-line-text">
                <x-forms.textarea name="lines[__INDEX__][description]" rows="2" maxlength="1000"></x-forms.textarea>
            </td>
            <td class="erp-entry-line-text line-card-full">
                <x-forms.input type="hidden" name="lines[__INDEX__][stage_selection_present]" value="1" />
                <x-forms.select variant="ajax" name="lines[__INDEX__][stage_public_ids][]" :url="route('admin.production.work-orders.select2.stages')" :placeholder="__('production_execution.orders.select_stages')" multiple />
                <div class="small text-600 mt-1">{{ __('production_execution.orders.line_route_help') }}
                    @can('production.product_stages.view')
                        <a href="{{ route('admin.production.product-stages.index') }}">{{ __('production_execution.product_stages.configure') }}</a>
                    @endcan
                </div>
            </td>
            <td class="erp-entry-line-text">
                <x-forms.textarea name="lines[__INDEX__][production_notes]" rows="2" maxlength="2000"></x-forms.textarea>
            </td>
            <td class="erp-entry-line-actions">
                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-link text-600 p-0" type="button" data-duplicate-production-line title="{{ __('production_execution.orders.duplicate_line_shortcut') }}" data-bs-title="{{ __('production_execution.orders.duplicate_line_shortcut') }}" aria-label="{{ __('production_execution.actions.duplicate_line') }}">
                        <span class="fas fa-copy"></span>
                    </button>
                    <button class="btn btn-link text-danger p-0" type="button" data-remove-production-line title="{{ __('production_execution.orders.delete_line_shortcut') }}" data-bs-title="{{ __('production_execution.orders.delete_line_shortcut') }}" aria-label="{{ __('production_execution.actions.remove_line') }}">
                        <span class="fas fa-trash-alt"></span>
                    </button>
                </div>
            </td>
        </tr>
    </template>

    <script type="application/json" data-production-order-initial-lines>{!! \Illuminate\Support\Js::encode($initialLines) !!}</script>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">
@endpush
@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>
@endpush
