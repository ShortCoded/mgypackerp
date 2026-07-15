@extends('layouts.app')

@php
    $record = $identifier ?? null;
    $isView = $mode === 'view';
    $isClone = $mode === 'clone';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $title = match ($mode) {
        'edit' => __('production_identifiers.edit'),
        'view' => __('production_identifiers.view'),
        'clone' => __('production_identifiers.clone'),
        default => __('production_identifiers.create'),
    };
    $value = fn (string $field, mixed $default = '') => old($field, $record?->{$field} ?? $default);
    $documentNumberValue = ! $isCreateLike ? $record?->doc_number : '';
    $parentDisplay = $record?->parent ? trim(implode(' / ', array_filter([$record->parent->doc_num, $record->parent->name]))) : null;
    $parentOption = $record?->parent && $record->parent->status === 'active' && $record->parent->is_group ? ['id' => $record->parent->doc_num, 'text' => $parentDisplay] : null;
    $showsDocumentNumberColumn = true;
    $originalIdentifierData = [
        'name' => (string) ($record?->name ?? ''),
        'parent_doc_num' => (string) ($record?->parent?->doc_num ?? ''),
        'is_group' => (bool) ($record?->is_group ?? false),
        'status' => (string) ($record?->status ?? 'active'),
        'notes' => (string) ($record?->notes ?? ''),
    ];
    $productionIdentifierMessages = [
        'deleteConfirmTitle' => __('production_identifiers.messages.delete_confirm_title'),
        'deleteConfirmText' => __('production_identifiers.messages.delete_confirm_text'),
        'deleteConfirmYes' => __('production_identifiers.messages.delete_confirm_yes'),
        'restoreConfirmTitle' => __('production_identifiers.messages.restore_confirm_title'),
        'restoreConfirmText' => __('production_identifiers.messages.restore_confirm_text'),
        'restoreConfirmYes' => __('production_identifiers.messages.restore_confirm_yes'),
        'cancel' => __('common.actions.cancel'),
        'validationFailed' => __('common.messages.validation_failed'),
        'unexpectedError' => __('common.messages.unexpected_error'),
        'saved' => __('common.messages.saved_successfully'),
        'noChanges' => __('common.messages.no_changes'),
        'groupLabel' => __('production_identifiers.attributes.is_group'),
    ];
@endphp

@section('title', $title)

@section('content')
    <form id="production-identifier-form" class="js-production-identifier-form" action="{{ $action }}" method="{{ $method }}" data-mode="{{ $mode }}" data-original='@json($originalIdentifierData)' novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if ($isClone && $cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card mb-3">
            @include('modules.production.identifiers.partials.form-header')

            <div class="card-body js-production-identifier-form-body">
                <div class="alert d-none js-production-identifier-alert" role="alert">
                    <div class="js-production-identifier-alert-message"></div>
                </div>
                <div class="row g-3 align-items-start">
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label" for="production-identifier-doc-number">{{ __('common.fields.document_number') }}</label>
                        <input id="production-identifier-doc-number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" class="text-center form-control" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}" readonly disabled>
                        <div class="form-text">{{ __('item_lookups.document_number_control.helper') }}</div>
                        <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                    </div>

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-9 col-lg-5' : 'col-md-6' }}">
                        <x-forms.label for="name" :label="__('production_identifiers.attributes.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="name" :value="$value('name')" />
                        @else
                            <input class="form-control" id="name" name="name" value="{{ $value('name') }}" required autofocus>
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-8 col-lg-4' : 'col-md-6' }}">
                        <label class="form-label" for="parent_doc_num">{{ __('production_identifiers.attributes.parent') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="parent_doc_num" :value="$parentDisplay ?? __('production_identifiers.attributes.no_parent')" />
                        @else
                            <select class="form-select js-select2-ajax" id="parent_doc_num" name="parent_doc_num" data-url="{{ route('admin.production.select2.identifiers', array_filter(['exclude' => $record?->doc_num])) }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="true">
                                @if ($parentOption)
                                    <option value="{{ $parentOption['id'] }}" selected>{{ $parentOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="parent_doc_num"></div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <x-forms.label for="status" :label="__('production_identifiers.attributes.status')" required />
                        @if ($isView)
                            <x-forms.view-field for="status" :value="__('production_identifiers.statuses.' . ($record?->status ?? 'active'))" />
                        @else
                            <select class="form-select" id="status" name="status" required>
                                @foreach (['active', 'inactive'] as $status)
                                    <option value="{{ $status }}" @selected($value('status', 'active') === $status)>{{ __('production_identifiers.statuses.' . $status) }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    <div class="col-12">
                        @if ($isView)
                            <div class="d-inline-flex align-items-center gap-2">
                                <span class="text-700 fs-10">{{ __('production_identifiers.attributes.is_group') }}</span>
                                <span class="badge rounded-pill badge-subtle-{{ $record?->is_group ? 'success' : 'secondary' }}">
                                    {{ $record?->is_group ? __('common.actions.yes') : __('common.actions.no') }}
                                </span>
                            </div>
                        @else
                            <input type="hidden" name="is_group" value="0">
                            <div class="mb-0 form-check form-switch">
                                <input class="form-check-input" id="is_group" name="is_group" type="checkbox" value="1" @checked(old('is_group', $record?->is_group ?? false))>
                                <label class="form-check-label text-700 fs-10" for="is_group">{{ __('production_identifiers.attributes.is_group') }}</label>
                            </div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="is_group"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('production_identifiers.attributes.notes') }}</label>
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
            @include('modules.production.identifiers.partials.form-footer')
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        window.productionIdentifierMessages = @json($productionIdentifierMessages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Production/identifiers.js') }}?v={{ filemtime(public_path('assets/js/modules/Production/identifiers.js')) }}"></script>
@endpush
