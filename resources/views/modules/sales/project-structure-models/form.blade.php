@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isClone = $mode === 'clone';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $title = match ($mode) {
        'edit' => __('project_structure_models.edit'),
        'view' => __('project_structure_models.view'),
        'clone' => __('project_structure_models.clone'),
        default => __('project_structure_models.create'),
    };
    $value = fn (string $field, mixed $default = '') => old($field, $record?->{$field} ?? $default);
    $recordName = $record ? ($isClone ? __('project_structure_models.defaults.clone_name', ['name' => $record->name]) : $record->name) : '';
    $showsDocumentNumberColumn = $canControlDocumentNumber || ! $isCreateLike;
    $originalRecordData = [
        'doc_number' => ! $isCreateLike ? (string) ($record?->doc_number ?? '') : '',
        'name' => (string) ($recordName ?? ''),
        'code' => $isClone ? '' : (string) ($record?->code ?? ''),
        'short_name' => $isClone ? '' : (string) ($record?->short_name ?? ''),
        'status' => (string) ($record?->status ?? 'active'),
        'notes' => (string) ($record?->notes ?? ''),
    ];
    $messages = [
        'deleteConfirmTitle' => __('project_structure_models.messages.delete_confirm_title'),
        'deleteConfirmText' => __('project_structure_models.messages.delete_confirm_text'),
        'deleteConfirmYes' => __('project_structure_models.messages.delete_confirm_yes'),
        'restoreConfirmTitle' => __('project_structure_models.messages.restore_confirm_title'),
        'restoreConfirmText' => __('project_structure_models.messages.restore_confirm_text'),
        'restoreConfirmYes' => __('project_structure_models.messages.restore_confirm_yes'),
        'cancel' => __('common.actions.cancel'),
        'validationFailed' => __('common.messages.validation_failed'),
        'unexpectedError' => __('common.messages.unexpected_error'),
        'saved' => __('common.messages.saved_successfully'),
        'noChanges' => __('common.messages.no_changes'),
    ];
@endphp

@section('title', $title)

@section('content')
    <form id="project-structure-model-form" class="js-project-structure-model-form" action="{{ $action }}" method="{{ $method }}" data-mode="{{ $mode }}" data-original='@json($originalRecordData)' novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="{{ $isCreateLike ? 'save_new' : 'save' }}">
        @if ($isClone && $cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card mb-3">
            @include('modules.sales.project-structure-models.partials.form-header')

            <div class="card-body js-project-structure-model-form-body">
                <div class="alert d-none js-project-structure-model-alert" role="alert">
                    <div class="js-project-structure-model-alert-message"></div>
                </div>

                <div class="row g-3 align-items-start">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-3 col-xl-2">
                            <label class="form-label" for="doc_number">{{ __('project_structure_models.attributes.doc_number') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="doc_number" as="display" :value="old('doc_number', ! $isCreateLike ? $record?->doc_number : '')" input-class="text-center" dir="ltr" />
                            @else
                                <input class="form-control text-center" id="doc_number" name="doc_number" type="number" min="1" step="1" dir="ltr" value="{{ old('doc_number', ! $isCreateLike ? $record?->doc_number : '') }}">
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif (! $isCreateLike)
                        <div class="col-md-3 col-xl-2">
                            <x-forms.view-field for="doc_num" :label="__('project_structure_models.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" dir="ltr" />
                        </div>
                    @endif

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-9 col-xl-4' : 'col-md-6 col-xl-4' }}">
                        <x-forms.label for="name" :label="__('project_structure_models.attributes.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="name" :value="old('name', $recordName)" />
                        @else
                            <input class="form-control" id="name" name="name" value="{{ old('name', $recordName) }}" required autofocus>
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-6 col-xl-3' : 'col-md-3 col-xl-4' }}">
                        <x-forms.label for="code" :label="__('project_structure_models.attributes.code')" required />
                        @if ($isView)
                            <x-forms.view-field for="code" :value="$isClone ? '' : $value('code')" input-class="text-center" />
                        @else
                            <input class="form-control" id="code" name="code" value="{{ $isClone ? '' : $value('code') }}" required>
                        @endif
                        <div class="invalid-feedback" data-error-for="code"></div>
                    </div>

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-6 col-xl-3' : 'col-md-3 col-xl-4' }}">
                        <x-forms.label for="short_name" :label="__('project_structure_models.attributes.short_name')" required />
                        @if ($isView)
                            <x-forms.view-field for="short_name" :value="$isClone ? '' : $value('short_name')" input-class="text-center" />
                        @else
                            <input class="form-control" id="short_name" name="short_name" value="{{ $isClone ? '' : $value('short_name') }}" required>
                        @endif
                        <div class="invalid-feedback" data-error-for="short_name"></div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <x-forms.label for="status" :label="__('project_structure_models.attributes.status')" required />
                        @if ($isView)
                            <x-forms.view-field for="status" :value="__('project_structure_models.statuses.' . ($record?->status ?? 'active'))" />
                        @else
                            <select class="form-select" id="status" name="status" required>
                                @foreach (['active', 'inactive'] as $status)
                                    <option value="{{ $status }}" @selected($value('status', 'active') === $status)>{{ __('project_structure_models.statuses.' . $status) }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('project_structure_models.attributes.notes') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="notes" as="textarea" :value="$value('notes')" rows="4" />
                        @else
                            <textarea class="form-control" id="notes" name="notes" rows="4">{{ $value('notes') }}</textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="notes"></div>
                    </div>
                </div>

                @if ($mode === 'edit' || $isView)
                    <x-audit-fields-row
                        :metadata="$metadata"
                        :show-deleted="$isView && ($record?->trashed() ?? false)"
                        :show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                    />
                @endif
            </div>

            @include('modules.sales.project-structure-models.partials.form-footer')
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        window.projectStructureModelMessages = @json($messages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Sales/project-structure-models.js') }}"></script>
@endpush
