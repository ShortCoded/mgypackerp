@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $title = $isView
        ? __('production_execution.stages.view_document', ['document' => $stage->code])
        : ($isEdit ? __('production_execution.stages.edit') : __('production_execution.stages.create'));
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $routePrefix = 'admin.production.stages';
@endphp

@section('title', $title)

@section('content')
    <div class="production-mobile-workflow">
        <form method="POST" action="{{ $isEdit ? route($routePrefix.'.update', $stage) : route($routePrefix.'.store') }}">
            @csrf
            @if($isEdit)
                @method('PUT')
            @endif
            <x-forms.input type="hidden" name="submit_action" value="save" />

            <div class="card mb-3">
                <div class="card-header py-2">
                    <div class="row flex-between-center g-2">
                        <div class="col"><h5 class="mb-0">{{ $title }}</h5></div>
                        <div class="col-auto">
                            @include('modules.finance.partials.form-actions', [
                                'mode' => $mode,
                                'record' => $stage,
                                'resource' => 'production.stages',
                                'routePrefix' => $routePrefix,
                                'canClone' => false,
                            ])
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if($errors->any())
                        <div class="alert alert-danger" role="alert">
                            <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                        </div>
                    @endif

                    <div class="row g-3">
                        <div class="col-md-3">
                            @if($isEdit || $isView)
                                <x-forms.view-field for="production-stage-code" :label="__('production_execution.fields.code')" :value="$stage->code" />
                            @else
                                <x-forms.label for="production-stage-code" :label="__('production_execution.fields.code')" />
                                <div id="production-stage-code" class="form-control-plaintext text-muted">{{ __('production_execution.stages.code_generated') }}</div>
                            @endif
                        </div>
                        <div class="col-md-5">
                            @if($isView)
                                <x-forms.view-field for="production-stage-name" :label="__('production_execution.fields.name')" :value="$stage->name" />
                            @else
                                <x-forms.label for="production-stage-name" :label="__('production_execution.fields.name')" required />
                                <x-forms.input id="production-stage-name" name="name" maxlength="255" value="{{ old('name', $stage?->name) }}" required />
                                @error('name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            @endif
                        </div>
                        <div class="col-md-4">
                            @if($isView)
                                <x-forms.view-field for="production-stage-output" :label="__('production_execution.fields.output_type')" :value="$stage->output_type" />
                            @else
                                <x-forms.label for="production-stage-output" :label="__('production_execution.fields.output_type')" />
                                <x-forms.input id="production-stage-output" name="output_type" maxlength="80" value="{{ old('output_type', $stage?->output_type) }}" />
                            @endif
                        </div>
                        <div class="col-md-4">
                            @if($isView)
                                <x-forms.view-field for="production-stage-duration" :label="__('production_execution.fields.standard_duration')" :value="$stage->standard_duration_value !== null ? $numbers->format($stage->standard_duration_value).' '.__('production_execution.duration_units.'.$stage->standard_duration_unit) : null" />
                            @else
                                <x-forms.label for="production-stage-duration" :label="__('production_execution.fields.standard_duration')" />
                                <x-forms.numeric-input id="production-stage-duration" name="standard_duration_value" :value="old('standard_duration_value', $stage?->standard_duration_value)" :scale="4" min="0.0001" step="0.0001" arrow-step="0.25" />
                                @error('standard_duration_value')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            @endif
                        </div>
                        <div class="col-md-4">
                            @if($isView)
                                <x-forms.view-field for="production-stage-duration-unit" :label="__('production_execution.fields.duration_unit')" :value="$stage->standard_duration_unit ? __('production_execution.duration_units.'.$stage->standard_duration_unit) : null" />
                            @else
                                <x-forms.label for="production-stage-duration-unit" :label="__('production_execution.fields.duration_unit')" />
                                <x-forms.select variant="local" id="production-stage-duration-unit" name="standard_duration_unit" :allow-clear="false">
                                    <option value="">—</option>
                                    <option value="hours" @selected(old('standard_duration_unit', $stage?->standard_duration_unit) === 'hours')>{{ __('production_execution.duration_units.hours') }}</option>
                                    <option value="days" @selected(old('standard_duration_unit', $stage?->standard_duration_unit) === 'days')>{{ __('production_execution.duration_units.days') }}</option>
                                </x-forms.select>
                                @error('standard_duration_unit')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            @endif
                        </div>
                        <div class="col-md-2">
                            @if($isView)
                                <x-forms.view-field for="production-stage-order" :label="__('production_execution.fields.order')" :value="$stage->display_order" />
                            @else
                                <x-forms.label for="production-stage-order" :label="__('production_execution.fields.order')" required />
                                <x-forms.numeric-input id="production-stage-order" name="display_order" :value="old('display_order', $stage?->display_order ?? 1)" :scale="0" min="1" step="1" arrow-step="1" required />
                                @error('display_order')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            @endif
                        </div>
                        <div class="col-md-2">
                            @if($isView)
                                <x-forms.view-field for="production-stage-status" :label="__('common.fields.status')" :value="__('production_execution.statuses.'.$stage->status)" />
                            @else
                                <x-forms.label for="production-stage-status" :label="__('common.fields.status')" required />
                                <x-forms.select variant="local" id="production-stage-status" name="status" :allow-clear="false" required>
                                    <option value="active" @selected(old('status', $stage?->status ?? 'active') === 'active')>{{ __('production_execution.statuses.active') }}</option>
                                    <option value="inactive" @selected(old('status', $stage?->status ?? 'active') === 'inactive')>{{ __('production_execution.statuses.inactive') }}</option>
                                </x-forms.select>
                            @endif
                        </div>
                        <div class="col-12">
                            @if($isView)
                                <x-forms.view-field for="production-stage-description" :label="__('production_execution.fields.description')" :value="$stage->description" />
                            @else
                                <x-forms.label for="production-stage-description" :label="__('production_execution.fields.description')" />
                                <x-forms.textarea id="production-stage-description" name="description" rows="4" maxlength="5000">{{ old('description', $stage?->description) }}</x-forms.textarea>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    @include('modules.finance.partials.form-actions', [
                        'mode' => $mode,
                        'record' => $stage,
                        'resource' => 'production.stages',
                        'routePrefix' => $routePrefix,
                        'canClone' => false,
                    ])
                </div>
            </div>
        </form>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">
@endpush
