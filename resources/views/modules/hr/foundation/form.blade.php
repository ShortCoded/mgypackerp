@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isClone = $mode === 'clone';
    $title = match ($mode) {
        'edit' => __('hr.' . $definition->translationKey . '.edit'),
        'view' => __('hr.' . $definition->translationKey . '.view'),
        'clone' => __('hr.titles.clone'),
        default => __('hr.' . $definition->translationKey . '.create'),
    };
    $recordName = $isClone && $record ? __('hr.defaults.clone_name', ['name' => $record->name]) : $record?->name;
    $documentNumberValue = old('doc_number', ($isEdit || $isView) ? $record?->doc_number : '');
    $fieldValue = function (array $field) use ($record, $isClone, $selectedRelations) {
        $name = (string) $field['name'];

        if (($field['type'] ?? null) === 'relation') {
            return old($name, $isClone ? ($selectedRelations[$name]['id'] ?? '') : ($selectedRelations[$name]['id'] ?? ''));
        }

        if (($field['type'] ?? null) === 'checkbox') {
            return (bool) old($name, $record?->getAttribute((string) ($field['column'] ?? $name)) ?? ($field['default'] ?? false));
        }

        if (($field['type'] ?? null) === 'weekdays') {
            return (array) old($name, $record?->getAttribute((string) ($field['column'] ?? $name)) ?? []);
        }

        return old($name, $record?->getAttribute((string) ($field['column'] ?? $name)) ?? ($field['default'] ?? ''));
    };
    $originalRecordData = [
        'name' => $recordName,
        'doc_number' => $canControlDocumentNumber ? (($isEdit || $isView) ? $record?->doc_number : '') : null,
        'status' => old('status', $record?->status ?? 'active'),
        'notes' => $record?->notes,
    ];
    foreach ($definition->fields as $field) {
        $fieldName = (string) $field['name'];
        $fieldType = $field['type'] ?? null;
        $value = $fieldValue($field);

        if ($fieldType === 'checkbox') {
            $originalRecordData[$fieldName] = $value ? '1' : '0';

            continue;
        }

        if ($fieldType === 'weekdays') {
            $days = is_array($value) ? $value : [];
            $order = $field['options'] ?? [];
            $ordered = array_values(array_filter($order, static fn (string $day): bool => in_array($day, $days, true)));
            $originalRecordData[$fieldName] = implode(',', $ordered);

            continue;
        }

        $originalRecordData[$fieldName] = is_scalar($value) || $value === null
            ? (string) ($value ?? '')
            : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
    $showsDocumentNumberColumn = $canControlDocumentNumber || (($isEdit || $isView) && ! $canControlDocumentNumber);
@endphp

@section('title', $title)

@push('styles')
    <style>
        .hr-select2-inline-control {
            display: flex;
            flex-direction: column;
            gap: .5rem;
        }

        .hr-select2-inline-control .js-inline-lookup-create {
            align-self: flex-end;
            white-space: nowrap;
        }

        .hr-select2-inline-control .select2-container {
            width: 100% !important;
        }
    </style>
@endpush

@section('content')
    <form id="hr-foundation-form" class="js-hr-foundation-form" action="{{ $action }}" method="{{ $method }}" data-mode="{{ $mode }}" data-original='@json($originalRecordData)' novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if ($isClone && $cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card">
            @include('modules.hr.foundation.partials.form-header')

            <div class="card-body js-hr-foundation-form-body">
                <div class="alert alert-danger alert-dismissible fade show d-none js-hr-foundation-alert" role="alert">
                    <span class="js-hr-foundation-alert-message"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                </div>

                <div class="row g-3 align-items-start">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="hr-foundation-doc-number">{{ __('common.fields.document_number') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="hr-foundation-doc-number" as="display" :value="$documentNumberValue" input-class="text-center js-hr-foundation-doc-number" />
                            @else
                                <input id="hr-foundation-doc-number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" class="text-center form-control js-hr-foundation-doc-number" value="{{ $documentNumberValue }}" placeholder="{{ __('hr.document_number_control.placeholder') }}">
                            @endif
                            <div class="form-text">{{ __('hr.document_number_control.helper') }}</div>
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif ($isEdit || $isView)
                        <div class="col-md-3 col-lg-2">
                            <x-forms.view-field
                                for="hr-foundation-doc-number-display"
                                as="display"
                                :label="__('common.fields.doc_number')"
                                :value="$record?->doc_number"
                                input-class="text-center"
                            />
                        </div>
                    @endif

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-6 col-lg-7' : 'col-md-8 col-lg-9' }}">
                        <x-forms.label for="hr-foundation-name" :label="__('common.fields.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="hr-foundation-name" :value="old('name', $recordName)" />
                        @else
                            <input id="hr-foundation-name" autofocus name="name" type="text" class="form-control" value="{{ old('name', $recordName) }}" required>
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="col-md-3">
                        @if ($isView)
                            <x-forms.view-field
                                for="hr-foundation-status"
                                :label="__('common.fields.status')"
                                :value="__('hr.statuses.' . old('status', $record?->status ?? 'active'))"
                                required
                            />
                        @else
                            <x-forms.label for="hr-foundation-status" :label="__('common.fields.status')" required />
                            <select id="hr-foundation-status" name="status" class="form-select" required>
                                @foreach (['active', 'inactive'] as $status)
                                    <option value="{{ $status }}" @selected(old('status', $record?->status ?? 'active') === $status)>{{ __("hr.statuses.{$status}") }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    @foreach ($definition->fields as $field)
                        @php
                            $fieldName = (string) $field['name'];
                            $fieldType = (string) ($field['type'] ?? 'text');
                            $fieldLabel = __('hr.foundation.attributes.' . $fieldName);
                            $value = $fieldValue($field);
                        @endphp

                        <div class="{{ in_array($fieldType, ['checkbox'], true) ? 'col-md-4 col-lg-3 d-flex align-items-end' : (in_array($fieldType, ['textarea', 'weekdays'], true) ? 'col-12' : 'col-md-6 col-lg-4') }}">
                            @if ($isView)
                                @if ($fieldType === 'checkbox')
                                    <x-forms.view-field :for="'hr-foundation-'.$fieldName" :label="$fieldLabel" :value="$value ? __('common.actions.yes') : __('common.actions.no')" />
                                @elseif ($fieldType === 'weekdays')
                                    <x-forms.view-field :for="'hr-foundation-'.$fieldName" :label="$fieldLabel" :value="collect($value)->map(fn ($day) => __('hr.foundation.weekdays.' . $day))->implode(' / ')" />
                                @elseif ($fieldType === 'select')
                                    <x-forms.view-field :for="'hr-foundation-'.$fieldName" :label="$fieldLabel" :value="__('hr.foundation.options.'.$fieldName.'.'.$value)" />
                                @elseif ($fieldType === 'relation')
                                    <x-forms.view-field :for="'hr-foundation-'.$fieldName" :label="$fieldLabel" :value="$selectedRelations[$fieldName]['text'] ?? null" />
                                @elseif ($fieldType === 'textarea')
                                    <x-forms.view-field :for="'hr-foundation-'.$fieldName" as="textarea" :label="$fieldLabel" :value="$value" rows="4" />
                                @else
                                    <x-forms.view-field :for="'hr-foundation-'.$fieldName" :label="$fieldLabel" :value="$value" />
                                @endif
                            @elseif ($fieldType === 'checkbox')
                                <div class="w-100">
                                    <input type="hidden" name="{{ $fieldName }}" value="0">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" id="hr-foundation-{{ $fieldName }}" name="{{ $fieldName }}" type="checkbox" value="1" @checked($value)>
                                        <label class="form-check-label" for="hr-foundation-{{ $fieldName }}">{{ $fieldLabel }}</label>
                                    </div>
                                    <div class="invalid-feedback d-block" data-error-for="{{ $fieldName }}"></div>
                                </div>
                            @elseif ($fieldType === 'select')
                                <label class="form-label" for="hr-foundation-{{ $fieldName }}">{{ $fieldLabel }}</label>
                                <select id="hr-foundation-{{ $fieldName }}" name="{{ $fieldName }}" class="form-select">
                                    @foreach (($field['options'] ?? []) as $option)
                                        <option value="{{ $option }}" @selected((string) $value === (string) $option)>{{ __('hr.foundation.options.'.$fieldName.'.'.$option) }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback" data-error-for="{{ $fieldName }}"></div>
                            @elseif ($fieldType === 'weekdays')
                                <label class="form-label d-block">{{ $fieldLabel }}</label>
                                <div class="row g-2">
                                    @foreach (($field['options'] ?? []) as $option)
                                        <div class="col-6 col-md-4 col-lg-3">
                                            <div class="form-check">
                                                <input class="form-check-input" id="hr-foundation-{{ $fieldName }}-{{ $option }}" name="{{ $fieldName }}[]" type="checkbox" value="{{ $option }}" @checked(in_array($option, $value, true))>
                                                <label class="form-check-label" for="hr-foundation-{{ $fieldName }}-{{ $option }}">{{ __('hr.foundation.weekdays.' . $option) }}</label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="invalid-feedback d-block" data-error-for="{{ $fieldName }}"></div>
                            @elseif ($fieldType === 'relation')
                                @php
                                    $relationSelected = $selectedRelations[$fieldName] ?? null;
                                    $inline = $relationInlineMeta[$fieldName] ?? ['can_create' => false, 'inline_url' => null];
                                    $relPlaceholder = __('hr.foundation.placeholders.' . $fieldName);
                                @endphp
                                @include('modules.hr.partials.inline-select2-field', [
                                    'isView' => $isView,
                                    'inputId' => 'hr-foundation-'.$fieldName,
                                    'fieldName' => $fieldName,
                                    'fieldLabel' => $fieldLabel,
                                    'placeholder' => $relPlaceholder,
                                    'selectedValue' => (string) $fieldValue($field),
                                    'selectedText' => (string) ($relationSelected['text'] ?? ''),
                                    'dataUrl' => route('admin.hr.select2.foundation', $field['select2']),
                                    'canCreate' => (bool) ($inline['can_create'] ?? false),
                                    'inlineUrl' => $inline['inline_url'] ?? null,
                                    'inlineMergeFields' => [],
                                ])
                            @elseif ($fieldType === 'textarea')
                                <label class="form-label" for="hr-foundation-{{ $fieldName }}">{{ $fieldLabel }}</label>
                                <textarea id="hr-foundation-{{ $fieldName }}" name="{{ $fieldName }}" class="form-control" rows="4">{{ $value }}</textarea>
                                <div class="invalid-feedback" data-error-for="{{ $fieldName }}"></div>
                            @else
                                <label class="form-label" for="hr-foundation-{{ $fieldName }}">{{ $fieldLabel }}</label>
                                <input id="hr-foundation-{{ $fieldName }}"
                                    name="{{ $fieldName }}"
                                    type="{{ in_array($fieldType, ['number', 'decimal'], true) ? 'number' : ($fieldType === 'time' ? 'time' : 'text') }}"
                                    class="form-control {{ $fieldType === 'date' ? 'datetimepicker' : '' }}"
                                    value="{{ $value }}"
                                    @if ($fieldType === 'decimal') step="0.01" @endif
                                    @if ($fieldType === 'number') step="1" @endif
                                    @if ($fieldType === 'date') placeholder="{{ __('common.placeholders.select_date') }}" data-options='{"disableMobile":true,"dateFormat":"Y-m-d"}' @endif>
                                <div class="invalid-feedback" data-error-for="{{ $fieldName }}"></div>
                            @endif
                        </div>
                    @endforeach

                    <div class="col-12">
                        <label class="form-label" for="hr-foundation-notes">{{ __('common.fields.notes') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="hr-foundation-notes" as="textarea" :value="old('notes', $record?->notes)" rows="4" />
                        @else
                            <textarea id="hr-foundation-notes" name="notes" class="form-control" rows="4">{{ old('notes', $record?->notes) }}</textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="notes"></div>
                    </div>
                </div>

                @if ($isEdit || $isView)
                    <x-audit-fields-row
                        :metadata="$metadata"
                        :show-deleted="$isView && ($record?->trashed() ?? false)"
                        :show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                    />
                @endif
            </div>

            @include('modules.hr.foundation.partials.form-footer')
        </div>
    </form>

    @unless ($isView)
        <div class="modal fade" id="hr-inline-lookup-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" id="hr-inline-lookup-form" method="POST" novalidate>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title js-inline-lookup-title">{{ __('hr.inline_lookup.title', ['lookup' => '']) }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div data-form-alert></div>
                        <input type="hidden" name="target_select" value="">
                        <div class="mb-3">
                            <x-forms.label for="hr-inline-lookup-name" :label="__('hr.inline_lookup.name')" required />
                            <input class="form-control" id="hr-inline-lookup-name" name="name" type="text" required>
                            <div class="invalid-feedback" data-error-for="name"></div>
                        </div>
                        <div>
                            <label class="form-label" for="hr-inline-lookup-notes">{{ __('hr.inline_lookup.notes') }}</label>
                            <textarea class="form-control" id="hr-inline-lookup-notes" name="notes" rows="3"></textarea>
                            <div class="invalid-feedback" data-error-for="notes"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-falcon-default" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                        <button type="submit" class="btn btn-primary">
                            <span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endunless
@endsection

@push('scripts')
    @php
        $hrMessages = [
            'noChanges' => __('common.messages.no_changes'),
            'validationSummary' => __('common.messages.validation_failed'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'close' => __('auth.alerts.close'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'loading' => __('common.messages.loading'),
            'deleteConfirmTitle' => __('hr.messages.delete_confirm_title'),
            'deleteConfirmText' => __('hr.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('hr.messages.delete_confirm_yes'),
            'restore' => __('hr.trash.restore'),
            'restoreConfirmTitle' => __('hr.trash.restore_confirm_title'),
            'restoreConfirmText' => __('hr.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('hr.trash.restore_confirm_yes'),
        ];
    @endphp
    <script>
        window.hrFoundationMessages = @json($hrMessages);
    </script>
    @php
        $hrInlineSelect2Messages = [
            'inlineLookupTitle' => __('hr.inline_lookup.title', ['lookup' => ':lookup']),
            'inlineLookupCreated' => __('hr.inline_lookup.created'),
            'validationFailed' => __('common.messages.validation_failed'),
            'validationSummaryTitle' => __('hr.inline_lookup.validation_summary'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
        ];
    @endphp
    <script>
        window.hrInlineSelect2Messages = @json($hrInlineSelect2Messages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/HR/hr-inline-select2.js') }}"></script>
    <script src="{{ asset('assets/js/modules/HR/hr-foundation.js') }}"></script>
@endpush
