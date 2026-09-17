@extends('layouts.app')

@php($selected = $routeStages->keyBy('production_stage_id'))

@section('title', __('production_execution.product_stages.configure'))

@section('content')
    <div class="production-mobile-workflow">
        <form method="POST" action="{{ route('admin.production.product-stages.update', $product) }}">
            @csrf
            @method('PUT')
            <x-forms.input type="hidden" name="product_id" value="{{ $product->id }}" />

            <div class="card mb-3">
                <div class="card-header py-2">
                    <div class="row flex-between-center g-2">
                        <div class="col"><h5 class="mb-0">{{ $product->doc_num }} — {{ $product->name }}</h5></div>
                        <div class="col-auto d-flex gap-2">
                            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.product-stages.index') }}">{{ __('common.actions.cancel') }}</a>
                            <button class="btn btn-primary btn-sm" type="submit"><span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if($errors->any())
                        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                    @endif

                    <h6 class="mb-3 text-700">{{ __('production_execution.product_stages.selected_route') }}</h6>
                    <div class="row g-3">
                        @foreach($stages as $stage)
                            @php($route = $selected->get($stage->id))
                            <div class="col-md-6 col-xl-4">
                                <div class="border rounded-2 p-3 h-100">
                                    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                                        <label class="form-check mb-0">
                                            <x-forms.input class="form-check-input" type="checkbox" name="selected_stage_ids[]" value="{{ $stage->id }}" :checked="in_array($stage->id, old('selected_stage_ids', $selected->keys()->all()))" />
                                            <span class="form-check-label fw-semi-bold">{{ $stage->code }} — {{ $stage->name }}</span>
                                        </label>
                                        <span class="badge badge-subtle-secondary">{{ $stage->output_type ?: '—' }}</span>
                                    </div>
                                    <x-forms.label :for="'stage-sequence-'.$stage->id" :label="__('production_execution.fields.order')" />
                                    <x-forms.numeric-input :id="'stage-sequence-'.$stage->id" :name="'stage_sequences['.$stage->id.']'" :value="old('stage_sequences.'.$stage->id, $route?->sequence ?? $stage->display_order)" :scale="0" min="1" step="1" arrow-step="1" />
                                    <div class="mt-2 text-700">{{ __('production_execution.fields.standard_duration') }}: {{ $stage->standard_duration_value ? app(\Modules\Core\Services\NumericFormatService::class)->format($stage->standard_duration_value).' '.__('production_execution.duration_units.'.$stage->standard_duration_unit) : '—' }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if($components->isNotEmpty())
                        <div class="border-top mt-4 pt-3">
                            <h6 class="mb-3 text-700">{{ __('production_execution.product_stages.component_assignments') }}</h6>
                            <div class="row g-3">
                                @foreach($components as $component)
                                    <div class="col-md-6 col-xl-4">
                                        <div class="border rounded-2 p-3 h-100">
                                            <x-forms.label :for="'component-stage-'.$component->public_id" :label="trim(($component->componentProduct?->doc_num ?? '').' — '.($component->componentProduct?->name ?? ''))" />
                                            <x-forms.select variant="local" :id="'component-stage-'.$component->public_id" :name="'component_stage_ids['.$component->public_id.']'" :placeholder="__('production_execution.product_stages.first_stage_fallback')">
                                                @foreach($stages as $stage)
                                                    <option value="{{ $stage->id }}" @selected((string) old('component_stage_ids.'.$component->public_id, $component->production_stage_id) === (string) $stage->id)>{{ $stage->code }} — {{ $stage->name }}</option>
                                                @endforeach
                                            </x-forms.select>
                                            @error('component_stage_ids.'.$component->public_id)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a class="btn btn-falcon-default" href="{{ route('admin.production.product-stages.index') }}">{{ __('common.actions.cancel') }}</a>
                    <button class="btn btn-primary" type="submit"><span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}</button>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">
@endpush
