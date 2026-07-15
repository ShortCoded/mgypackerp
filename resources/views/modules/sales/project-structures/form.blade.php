@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isClone = $mode === 'clone';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $title = match ($mode) {
        'edit' => __('project_structures.edit'),
        'view' => __('project_structures.view'),
        'clone' => __('project_structures.clone'),
        default => __('project_structures.create'),
    };
    $value = fn (string $field, mixed $default = '') => old($field, $record?->{$field} ?? $default);
    $recordName = $record ? ($isClone ? __('project_structures.defaults.clone_name', ['name' => $record->name]) : $record->name) : '';
    $parentDisplay = $record?->parent ? $record->parent->label() : null;
    $parentOption = $record?->parent && $record->parent->status === 'active' && ! $record->parent->trashed() ? ['id' => $record->parent->doc_num, 'text' => $parentDisplay] : null;
    $showsDocumentNumberColumn = $canControlDocumentNumber || ! $isCreateLike;
    $originalRecordData = [
        'doc_number' => ! $isCreateLike ? (string) ($record?->doc_number ?? '') : '',
        'name' => (string) ($recordName ?? ''),
        'code' => $isClone ? '' : (string) ($record?->code ?? ''),
        'parent_doc_num' => (string) ($record?->parent?->doc_num ?? ''),
        'status' => (string) ($record?->status ?? 'active'),
        'notes' => (string) ($record?->notes ?? ''),
    ];
    $messages = [
        'deleteConfirmTitle' => __('project_structures.messages.delete_confirm_title'),
        'deleteConfirmText' => __('project_structures.messages.delete_confirm_text'),
        'deleteConfirmYes' => __('project_structures.messages.delete_confirm_yes'),
        'restoreConfirmTitle' => __('project_structures.messages.restore_confirm_title'),
        'restoreConfirmText' => __('project_structures.messages.restore_confirm_text'),
        'restoreConfirmYes' => __('project_structures.messages.restore_confirm_yes'),
        'cancel' => __('common.actions.cancel'),
        'validationFailed' => __('common.messages.validation_failed'),
        'unexpectedError' => __('common.messages.unexpected_error'),
        'saved' => __('common.messages.saved_successfully'),
        'noChanges' => __('common.messages.no_changes'),
    ];
@endphp

@section('title', $title)

@section('content')
    <form id="project-structure-form" class="js-project-structure-form" action="{{ $action }}" method="{{ $method }}" data-mode="{{ $mode }}" data-original='@json($originalRecordData)' novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="{{ $isCreateLike ? 'save_new' : 'save' }}">
        @if ($isClone && $cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card mb-3">
            @include('modules.sales.project-structures.partials.form-header')

            <div class="card-body js-project-structure-form-body">
                <div class="alert d-none js-project-structure-alert" role="alert">
                    <div class="js-project-structure-alert-message"></div>
                </div>

                <div class="row g-3 align-items-start">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-3 col-xl-2">
                            <label class="form-label" for="doc_number">{{ __('project_structures.attributes.doc_number') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="doc_number" as="display" :value="old('doc_number', ! $isCreateLike ? $record?->doc_number : '')" input-class="text-center" dir="ltr" />
                            @else
                                <input class="form-control text-center" id="doc_number" name="doc_number" type="number" min="1" step="1" dir="ltr" value="{{ old('doc_number', ! $isCreateLike ? $record?->doc_number : '') }}">
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif (! $isCreateLike)
                        <div class="col-md-3 col-xl-2">
                            <x-forms.view-field for="doc_num" :label="__('project_structures.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" dir="ltr" />
                        </div>
                    @endif

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-6 col-xl-7' : 'col-md-8' }}">
                        <x-forms.label for="name" :label="__('project_structures.attributes.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="name" :value="old('name', $recordName)" />
                        @else
                            <input class="form-control" id="name" name="name" value="{{ old('name', $recordName) }}" required autofocus>
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-3 col-xl-3' : 'col-md-4' }}">
                        <x-forms.label for="code" :label="__('project_structures.attributes.code')" required />
                        @if ($isView)
                            <x-forms.view-field for="code" :value="$isClone ? '' : $value('code')" input-class="text-center" />
                        @else
                            <input class="form-control" id="code" name="code" value="{{ $isClone ? '' : $value('code') }}" required>
                        @endif
                        <div class="invalid-feedback" data-error-for="code"></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="parent_doc_num">{{ __('project_structures.attributes.parent') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="parent_doc_num" :value="$parentDisplay ?? __('project_structures.attributes.no_parent')" />
                        @else
                            <select class="form-select js-select2-ajax" id="parent_doc_num" name="parent_doc_num" data-url="{{ route('admin.sales.select2.project-structures') }}?exclude={{ $record?->doc_num }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="true">
                                @if ($parentOption)
                                    <option value="{{ $parentOption['id'] }}" selected>{{ $parentOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="parent_doc_num"></div>
                    </div>

                    <div class="col-md-6">
                        <x-forms.label for="status" :label="__('project_structures.attributes.status')" required />
                        @if ($isView)
                            <x-forms.view-field for="status" :value="__('project_structures.statuses.' . ($record?->status ?? 'active'))" />
                        @else
                            <select class="form-select" id="status" name="status" required>
                                @foreach (['active', 'inactive'] as $status)
                                    <option value="{{ $status }}" @selected($value('status', 'active') === $status)>{{ __('project_structures.statuses.' . $status) }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('project_structures.attributes.notes') }}</label>
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

            @include('modules.sales.project-structures.partials.form-footer')
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        window.projectStructureMessages = @json($messages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Sales/project-structures.js') }}"></script>
@endpush
