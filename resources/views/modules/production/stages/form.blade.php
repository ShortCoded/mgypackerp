@extends('layouts.app')

@php($editing = $stage !== null)
@section('title', $editing ? __('production_execution.stages.edit') : __('production_execution.stages.create'))

@section('content')
    <div class="production-mobile-workflow">
    <form method="POST" action="{{ $editing ? route('admin.production.stages.update', $stage) : route('admin.production.stages.store') }}">
        @csrf
        @if($editing) @method('PUT') @endif
        <div class="card">
            <div class="card-header py-2">
                <div class="row flex-between-center g-2">
                    <div class="col"><h5 class="mb-0">{{ $editing ? __('production_execution.stages.edit') : __('production_execution.stages.create') }}</h5></div>
                    <div class="col-auto d-flex flex-wrap justify-content-end gap-2 mobile-action-row">
                        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.stages.index') }}">{{ __('common.actions.cancel') }}</a>
                        <button class="btn btn-falcon-default btn-sm" type="submit" name="submit_intent" value="save_and_new">{{ __('production_execution.actions.save_and_new') }}</button>
                        <button class="btn btn-falcon-default btn-sm" type="submit" name="submit_intent" value="save_and_edit">{{ __('production_execution.actions.save_and_edit') }}</button>
                        <button class="btn btn-primary btn-sm" type="submit" name="submit_intent" value="save_and_back">{{ __('production_execution.actions.save_and_back') }}</button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <div class="row g-3">
                    <div class="col-md-3"><x-forms.label for="production-stage-code" :label="__('production_execution.fields.code')" required /><x-forms.input id="production-stage-code" name="code" maxlength="80" value="{{ old('code', $stage?->code) }}" required /></div>
                    <div class="col-md-5"><x-forms.label for="production-stage-name" :label="__('production_execution.fields.name')" required /><x-forms.input id="production-stage-name" name="name" value="{{ old('name', $stage?->name) }}" required /></div>
                    <div class="col-md-2"><x-forms.label for="production-stage-order" :label="__('production_execution.fields.order')" required /><x-forms.numeric-input id="production-stage-order" name="display_order" :value="old('display_order', $stage?->display_order ?? 1)" :scale="0" min="1" step="1" arrow-step="1" required /></div>
                    <div class="col-md-2"><x-forms.label for="production-stage-status" :label="__('production_execution.fields.status')" required /><x-forms.select variant="local" id="production-stage-status" name="status" :allow-clear="false" required><option value="active" @selected(old('status', $stage?->status ?? 'active') === 'active')>{{ __('production_execution.statuses.active') }}</option><option value="inactive" @selected(old('status', $stage?->status) === 'inactive')>{{ __('production_execution.statuses.inactive') }}</option></x-forms.select></div>
                    <div class="col-md-4"><x-forms.label for="production-stage-output" :label="__('production_execution.fields.output_type')" /><x-forms.input id="production-stage-output" name="output_type" value="{{ old('output_type', $stage?->output_type) }}" /></div>
                    <div class="col-md-4"><x-forms.label for="production-stage-duration" :label="__('production_execution.fields.standard_duration')" /><x-forms.numeric-input id="production-stage-duration" name="standard_duration_value" :value="old('standard_duration_value', $stage?->standard_duration_value)" :scale="4" min="0.0001" step="0.0001" arrow-step="1" /></div>
                    <div class="col-md-4"><x-forms.label for="production-stage-duration-unit" :label="__('production_execution.fields.duration_unit')" /><x-forms.select variant="local" id="production-stage-duration-unit" name="standard_duration_unit"><option value="">—</option><option value="hours" @selected(old('standard_duration_unit', $stage?->standard_duration_unit) === 'hours')>{{ __('production_execution.duration_units.hours') }}</option><option value="days" @selected(old('standard_duration_unit', $stage?->standard_duration_unit) === 'days')>{{ __('production_execution.duration_units.days') }}</option></x-forms.select></div>
                    <div class="col-12"><x-forms.label for="production-stage-description" :label="__('production_execution.fields.description')" /><x-forms.textarea id="production-stage-description" name="description" rows="4">{{ old('description', $stage?->description) }}</x-forms.textarea></div>
                </div>
            </div>
            <div class="card-footer d-flex flex-wrap justify-content-end gap-2 mobile-action-row">
                <a class="btn btn-falcon-default" href="{{ route('admin.production.stages.index') }}">{{ __('common.actions.cancel') }}</a>
                <button class="btn btn-falcon-default" type="submit" name="submit_intent" value="save_and_new">{{ __('production_execution.actions.save_and_new') }}</button>
                <button class="btn btn-falcon-default" type="submit" name="submit_intent" value="save_and_edit">{{ __('production_execution.actions.save_and_edit') }}</button>
                <button class="btn btn-primary" type="submit" name="submit_intent" value="save_and_back">{{ __('production_execution.actions.save_and_back') }}</button>
            </div>
        </div>
    </form>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
