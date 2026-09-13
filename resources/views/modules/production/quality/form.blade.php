@extends('layouts.app')

@section('title', __('production_execution.quality.create_inspection'))

@section('content')
    <div class="production-mobile-workflow">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
            <div>
                <a class="small" href="{{ route('admin.production.quality.index') }}">{{ __('production_execution.quality.title') }}</a>
                <h4 class="mb-0">{{ __('production_execution.quality.create_inspection') }}</h4>
            </div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.production.quality.store') }}">
            @csrf
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('production_execution.quality.request_details') }}</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="quality-subject-type">{{ __('production_execution.fields.inspection_subject') }}</label>
                            <x-forms.select id="quality-subject-type" name="subject_type" required data-quality-subject-type autofocus>
                                @foreach(['production_run', 'inventory_stock', 'product'] as $subject)
                                    <option value="{{ $subject }}" @selected(old('subject_type', request()->filled('run') ? 'production_run' : 'production_run') === $subject)>{{ __('production_execution.quality_subjects.'.$subject) }}</option>
                                @endforeach
                            </x-forms.select>
                        </div>

                        <div class="col-12 col-md-6" data-quality-subject-field="production_run">
                            <label class="form-label" for="quality-production-run">{{ __('production_execution.fields.run') }}</label>
                            <x-forms.select :class="$errors->has('production_run_id') ? 'is-invalid' : null" id="quality-production-run" name="production_run_id">
                                <option value="">{{ __('common.actions.select') }}</option>
                                @foreach ($runs as $run)
                                    <option value="{{ $run->getKey() }}" @selected((string) old('production_run_id', request()->integer('run')) === (string) $run->getKey())>
                                        {{ $run->run_number }} — {{ $run->product?->name ?? '—' }}@if($run->stageSnapshot) — {{ $run->stageSnapshot->stage_name }}@endif — {{ __('production_execution.statuses.'.$run->status) }}
                                    </option>
                                @endforeach
                            </x-forms.select>
                            @error('production_run_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12 col-md-6" data-quality-subject-field="product,inventory_stock">
                            <label class="form-label" for="quality-product">{{ __('production_execution.fields.product') }}</label>
                            <x-forms.select id="quality-product" name="product_id">
                                <option value="">{{ __('common.actions.select') }}</option>
                                @foreach($products as $product)<option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>{{ $product->doc_num }} — {{ $product->name }}</option>@endforeach
                            </x-forms.select>
                        </div>

                        <div class="col-12 col-md-6" data-quality-subject-field="inventory_stock">
                            <label class="form-label" for="quality-store">{{ __('production_execution.fields.store') }}</label>
                            <x-forms.select id="quality-store" name="branch_store_id">
                                <option value="">{{ __('common.actions.select') }}</option>
                                @foreach($stores as $store)<option value="{{ $store->id }}" @selected((string) old('branch_store_id') === (string) $store->id)>{{ $store->name }}</option>@endforeach
                            </x-forms.select>
                        </div>

                        <div class="col-12 col-md-4" data-quality-subject-field="inventory_stock">
                            <label class="form-label" for="quality-stock-status">{{ __('production_execution.fields.stock_status') }}</label>
                            <x-forms.select id="quality-stock-status" name="stock_status">
                                @foreach(['available', 'quarantine', 'rework', 'damaged', 'scrap'] as $status)<option value="{{ $status }}" @selected(old('stock_status', 'available') === $status)>{{ __('production_execution.stock_statuses.'.$status) }}</option>@endforeach
                            </x-forms.select>
                        </div>

                        <div class="col-12 col-md-4" data-quality-subject-field="inventory_stock">
                            <label class="form-label" for="quality-batch-lot">{{ __('production_execution.fields.batch_lot') }}</label>
                            <x-forms.input id="quality-batch-lot" name="batch_lot" :value="old('batch_lot')" />
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label" for="quality-source-reference">{{ __('production_execution.fields.source_reference') }}</label>
                            <x-forms.input id="quality-source-reference" name="source_reference" :value="old('source_reference')" />
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="quality-inspection-type">{{ __('production_execution.fields.inspection_type') }}</label>
                            <x-forms.select :class="$errors->has('quality_inspection_type_id') ? 'is-invalid' : null" id="quality-inspection-type" name="quality_inspection_type_id">
                                <option value="">{{ __('production_execution.quality.general_stage_inspection') }}</option>
                                @foreach ($inspectionTypes as $inspectionType)
                                    <option value="{{ $inspectionType->getKey() }}" @selected((string) old('quality_inspection_type_id') === (string) $inspectionType->getKey())>{{ $inspectionType->name }}</option>
                                @endforeach
                            </x-forms.select>
                            @error('quality_inspection_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="quality-affected-quantity">{{ __('production_execution.fields.affected_quantity') }}</label>
                            <x-forms.input :class="$errors->has('affected_base_quantity') ? 'is-invalid' : null" id="quality-affected-quantity" name="affected_base_quantity" type="number" min="0" step="0.00000001" inputmode="decimal" :value="old('affected_base_quantity')" />
                            @error('affected_base_quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="quality-request-notes">{{ __('production_execution.fields.notes') }}</label>
                            <x-forms.textarea :class="$errors->has('notes') ? 'is-invalid' : null" id="quality-request-notes" name="notes" rows="4">{{ old('notes') }}</x-forms.textarea>
                            @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex flex-column flex-sm-row justify-content-end gap-2 mobile-action-row">
                    <a class="btn btn-falcon-default" href="{{ route('admin.production.quality.index') }}">{{ __('common.actions.cancel') }}</a>
                    <button class="btn btn-primary" type="submit"><span class="fas fa-paper-plane me-1"></span>{{ __('production_execution.actions.create_inspection') }}</button>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
