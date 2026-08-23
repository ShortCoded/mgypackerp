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

        $value = old($name, $record?->getAttribute((string) ($field['column'] ?? $name)) ?? ($field['default'] ?? ''));

        return ($field['type'] ?? null) === 'date' && $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : $value;
    };
    $taxBracketRows = $definition->hasTaxBrackets
        ? old('tax_brackets', $record?->brackets?->map(fn ($bracket) => [
            'public_uuid' => $bracket->public_uuid,
            'from_amount' => $bracket->from_amount,
            'to_amount' => $bracket->to_amount,
            'rate' => $bracket->rate,
            'notes' => $bracket->notes,
        ])->values()->all() ?? [['public_uuid' => '', 'from_amount' => '0', 'to_amount' => '', 'rate' => '0', 'notes' => '']])
        : [];
    $insuranceComponentRows = $definition->hasInsuranceComponents
        ? old('insurance_components', $record?->components?->map(fn ($component) => [
            'public_uuid' => $component->public_uuid,
            'name' => $component->name,
            'employee_rate' => $component->employee_rate,
            'employer_rate' => $component->employer_rate,
            'calculation_basis' => $component->calculation_basis,
            'is_active' => $component->is_active,
            'notes' => $component->notes,
        ])->values()->all() ?? [[
            'public_uuid' => '',
            'name' => '',
            'employee_rate' => '0',
            'employer_rate' => '0',
            'calculation_basis' => 'contribution_wage',
            'is_active' => true,
            'notes' => '',
        ]])
        : [];
    $insuranceEmployeeTotal = '0.0000';
    $insuranceEmployerTotal = '0.0000';
    foreach ($insuranceComponentRows as $componentRow) {
        if (filter_var($componentRow['is_active'] ?? false, FILTER_VALIDATE_BOOL)) {
            $insuranceEmployeeTotal = bcadd($insuranceEmployeeTotal, (string) ($componentRow['employee_rate'] ?? '0'), 4);
            $insuranceEmployerTotal = bcadd($insuranceEmployerTotal, (string) ($componentRow['employer_rate'] ?? '0'), 4);
        }
    }
    $insuranceCombinedTotal = bcadd($insuranceEmployeeTotal, $insuranceEmployerTotal, 4);
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
    foreach ($taxBracketRows as $index => $row) {
        foreach (['public_uuid', 'from_amount', 'to_amount', 'rate', 'notes'] as $column) {
            $originalRecordData["tax_brackets[{$index}][{$column}]"] = (string) ($row[$column] ?? '');
        }
    }
    foreach ($insuranceComponentRows as $index => $row) {
        foreach (['public_uuid', 'name', 'employee_rate', 'employer_rate', 'calculation_basis', 'notes'] as $column) {
            $originalRecordData["insurance_components[{$index}][{$column}]"] = (string) ($row[$column] ?? '');
        }
        $originalRecordData["insurance_components[{$index}][is_active]"] = filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOL) ? '1' : '0';
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
                            $isNumericField = in_array($fieldType, ['number', 'decimal'], true);
                            $numericScale = (int) ($field['scale'] ?? ($fieldType === 'number' ? 0 : 2));
                            $numericMin = $field['min'] ?? null;
                            $numericMax = $field['max'] ?? null;
                            $numericStep = $field['step'] ?? ($numericScale === 0 ? '1' : '0.'.str_repeat('0', max(0, $numericScale - 1)).'1');
                            $fieldRequired = in_array('required', $field['rules'] ?? [], true);
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
                                @elseif ($isNumericField)
                                    <x-forms.view-field :for="'hr-foundation-'.$fieldName" :label="$fieldLabel" :value="$value" numeric dir="ltr" />
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
                                <x-forms.label :for="'hr-foundation-'.$fieldName" :label="$fieldLabel" :required="$fieldRequired" />
                                <select id="hr-foundation-{{ $fieldName }}" name="{{ $fieldName }}" class="form-select" @required($fieldRequired)>
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
                            @elseif ($isNumericField)
                                <x-forms.label :for="'hr-foundation-'.$fieldName" :label="$fieldLabel" :required="$fieldRequired" />
                                <x-forms.numeric-input
                                    :id="'hr-foundation-'.$fieldName"
                                    :name="$fieldName"
                                    :value="$value"
                                    :scale="$numericScale"
                                    :min="$numericMin"
                                    :max="$numericMax"
                                    :step="$numericStep"
                                    class="text-center"
                                    :required="$fieldRequired"
                                />
                                <div class="invalid-feedback" data-error-for="{{ $fieldName }}"></div>
                            @else
                                <x-forms.label :for="'hr-foundation-'.$fieldName" :label="$fieldLabel" :required="$fieldRequired" />
                                <input id="hr-foundation-{{ $fieldName }}"
                                    name="{{ $fieldName }}"
                                    type="{{ $fieldType === 'time' ? 'time' : 'text' }}"
                                    class="form-control {{ $fieldType === 'date' ? 'datetimepicker' : '' }}"
                                    value="{{ $value }}"
                                    @required($fieldRequired)
                                    @if ($fieldType === 'date') placeholder="{{ __('common.placeholders.select_date') }}" data-options='{"disableMobile":true,"dateFormat":"Y-m-d"}' @endif>
                                <div class="invalid-feedback" data-error-for="{{ $fieldName }}"></div>
                            @endif
                            @if (isset($field['help']))
                                <div class="form-text">{{ __('hr.foundation.help.'.$field['help']) }}</div>
                            @endif
                        </div>
                    @endforeach

                    @if ($definition->hasInsuranceComponents)
                        <div class="col-12">
                            <div class="card border shadow-none">
                                <div class="card-header bg-body-tertiary d-flex align-items-start justify-content-between gap-3">
                                    <div>
                                        <h6 class="mb-1">{{ __('hr.foundation.insurance_components.title') }}</h6>
                                        <small class="text-muted">{{ __('hr.foundation.insurance_components.help') }}</small>
                                    </div>
                                    @unless ($isView)
                                        <button class="btn btn-sm btn-falcon-primary js-add-insurance-component" type="button">
                                            <span class="fas fa-plus me-1"></span>{{ __('hr.foundation.insurance_components.add') }}
                                        </button>
                                    @endunless
                                </div>
                                <div class="card-body">
                                    <div class="vstack gap-3 js-insurance-components">
                                        @foreach ($insuranceComponentRows as $index => $row)
                                            @php
                                                $componentActive = filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOL);
                                                $componentTotal = bcadd((string) ($row['employee_rate'] ?? '0'), (string) ($row['employer_rate'] ?? '0'), 4);
                                            @endphp
                                            <div class="border rounded-3 p-3 js-insurance-component-row" data-insurance-component-index="{{ $index }}">
                                                <input type="hidden" name="insurance_components[{{ $index }}][public_uuid]" value="{{ $row['public_uuid'] ?? '' }}">
                                                <div class="d-flex align-items-center justify-content-between mb-2">
                                                    <span class="badge rounded-pill bg-primary-subtle text-primary js-insurance-component-sequence">{{ $index + 1 }}</span>
                                                    @unless ($isView)
                                                        <button class="btn btn-sm btn-outline-danger js-remove-insurance-component" type="button" aria-label="{{ __('common.actions.delete') }}">
                                                            <span class="fas fa-trash-alt"></span>
                                                        </button>
                                                    @endunless
                                                </div>
                                                <div class="row g-2 align-items-end">
                                                    <div class="col-md-5 col-xl-3">
                                                        <label class="form-label" for="insurance-component-{{ $index }}-name">{{ __('hr.foundation.insurance_components.component') }}</label>
                                                        @if ($isView)
                                                            <div class="form-control-plaintext" id="insurance-component-{{ $index }}-name">{{ $row['name'] ?? '—' }}</div>
                                                        @else
                                                            <input class="form-control" id="insurance-component-{{ $index }}-name" name="insurance_components[{{ $index }}][name]" type="text" maxlength="255" value="{{ $row['name'] ?? '' }}" required>
                                                            <div class="invalid-feedback" data-error-for="insurance_components.{{ $index }}.name"></div>
                                                        @endif
                                                    </div>
                                                    @foreach (['employee_rate', 'employer_rate'] as $column)
                                                        <div class="col-6 col-md-3 col-xl-2">
                                                            <label class="form-label" for="insurance-component-{{ $index }}-{{ $column }}">{{ __('hr.foundation.insurance_components.'.$column) }}</label>
                                                            @if ($isView)
                                                                <div class="form-control-plaintext text-center" id="insurance-component-{{ $index }}-{{ $column }}">{{ $row[$column] ?? '0' }}%</div>
                                                            @else
                                                                <input class="form-control text-center js-insurance-component-rate" id="insurance-component-{{ $index }}-{{ $column }}" name="insurance_components[{{ $index }}][{{ $column }}]" type="number" min="0" max="100" step="0.0001" value="{{ $row[$column] ?? '0' }}" required>
                                                                <div class="invalid-feedback" data-error-for="insurance_components.{{ $index }}.{{ $column }}"></div>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                    <div class="col-6 col-md-3 col-xl-2">
                                                        <label class="form-label">{{ __('hr.foundation.insurance_components.total_rate') }}</label>
                                                        <div class="form-control-plaintext text-center fw-semibold js-insurance-component-total">{{ $componentTotal }}%</div>
                                                    </div>
                                                    <div class="col-md-5 col-xl-2">
                                                        <label class="form-label" for="insurance-component-{{ $index }}-calculation_basis">{{ __('hr.foundation.insurance_components.calculation_basis') }}</label>
                                                        @if ($isView)
                                                            <div class="form-control-plaintext" id="insurance-component-{{ $index }}-calculation_basis">{{ __('hr.foundation.insurance_components.bases.'.($row['calculation_basis'] ?? 'contribution_wage')) }}</div>
                                                        @else
                                                            <select class="form-select" id="insurance-component-{{ $index }}-calculation_basis" name="insurance_components[{{ $index }}][calculation_basis]" required>
                                                                <option value="contribution_wage" @selected(($row['calculation_basis'] ?? '') === 'contribution_wage')>{{ __('hr.foundation.insurance_components.bases.contribution_wage') }}</option>
                                                            </select>
                                                            <div class="invalid-feedback" data-error-for="insurance_components.{{ $index }}.calculation_basis"></div>
                                                        @endif
                                                    </div>
                                                    <div class="col-md-2 col-xl-1">
                                                        @if ($isView)
                                                            <label class="form-label">{{ __('hr.foundation.insurance_components.active') }}</label>
                                                            <div class="form-control-plaintext">{{ $componentActive ? __('common.actions.yes') : __('common.actions.no') }}</div>
                                                        @else
                                                            <input type="hidden" name="insurance_components[{{ $index }}][is_active]" value="0">
                                                            <div class="form-check form-switch mb-2">
                                                                <input class="form-check-input js-insurance-component-active" id="insurance-component-{{ $index }}-is_active" name="insurance_components[{{ $index }}][is_active]" type="checkbox" value="1" @checked($componentActive)>
                                                                <label class="form-check-label" for="insurance-component-{{ $index }}-is_active">{{ __('hr.foundation.insurance_components.active') }}</label>
                                                            </div>
                                                            <div class="invalid-feedback d-block" data-error-for="insurance_components.{{ $index }}.is_active"></div>
                                                        @endif
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label" for="insurance-component-{{ $index }}-notes">{{ __('common.fields.notes') }}</label>
                                                        @if ($isView)
                                                            <div class="form-control-plaintext" id="insurance-component-{{ $index }}-notes">{{ ($row['notes'] ?? '') ?: '—' }}</div>
                                                        @else
                                                            <input class="form-control" id="insurance-component-{{ $index }}-notes" name="insurance_components[{{ $index }}][notes]" type="text" value="{{ $row['notes'] ?? '' }}">
                                                            <div class="invalid-feedback" data-error-for="insurance_components.{{ $index }}.notes"></div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="invalid-feedback d-block" data-error-for="insurance_components"></div>
                                </div>
                                <div class="card-footer bg-body-tertiary">
                                    <div class="row g-3 text-center">
                                        <div class="col-md-4">
                                            <small class="d-block text-muted">{{ __('hr.foundation.insurance_components.total_employee') }}</small>
                                            <strong class="js-insurance-employee-total">{{ $insuranceEmployeeTotal }}%</strong>
                                        </div>
                                        <div class="col-md-4">
                                            <small class="d-block text-muted">{{ __('hr.foundation.insurance_components.total_employer') }}</small>
                                            <strong class="js-insurance-employer-total">{{ $insuranceEmployerTotal }}%</strong>
                                        </div>
                                        <div class="col-md-4">
                                            <small class="d-block text-muted">{{ __('hr.foundation.insurance_components.total_combined') }}</small>
                                            <strong class="js-insurance-combined-total">{{ $insuranceCombinedTotal }}%</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($definition->hasTaxBrackets)
                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div>
                                    <h6 class="mb-0">{{ __('hr.foundation.tax_brackets.title') }}</h6>
                                    <small class="text-muted">{{ __('hr.foundation.tax_brackets.help') }}</small>
                                </div>
                                @unless ($isView)
                                    <button class="btn btn-sm btn-falcon-primary js-add-tax-bracket" type="button">
                                        <span class="fas fa-plus me-1"></span>{{ __('hr.foundation.tax_brackets.add') }}
                                    </button>
                                @endunless
                            </div>
                            <div class="vstack gap-2 js-tax-brackets">
                                @foreach ($taxBracketRows as $index => $row)
                                    <div class="border rounded-3 p-3 js-tax-bracket-row" data-tax-bracket-index="{{ $index }}">
                                        <input type="hidden" name="tax_brackets[{{ $index }}][public_uuid]" value="{{ $row['public_uuid'] ?? '' }}">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="badge rounded-pill bg-primary-subtle text-primary js-tax-bracket-sequence">{{ __('hr.foundation.tax_brackets.sequence') }} {{ $index + 1 }}</span>
                                            @unless ($isView)
                                                <button class="btn btn-sm btn-outline-danger js-remove-tax-bracket" type="button" aria-label="{{ __('common.actions.delete') }}">
                                                    <span class="fas fa-trash-alt"></span>
                                                </button>
                                            @endunless
                                        </div>
                                        <div class="row g-2 align-items-end">
                                            @foreach (['from_amount', 'to_amount', 'rate'] as $column)
                                                <div class="col-md-4">
                                                    <label class="form-label" for="tax-bracket-{{ $index }}-{{ $column }}">{{ __('hr.foundation.tax_brackets.'.$column) }}</label>
                                                    @if ($isView)
                                                        <div class="form-control-plaintext" id="tax-bracket-{{ $index }}-{{ $column }}">
                                                            {{ ($row[$column] ?? '') !== '' && ($row[$column] ?? null) !== null ? $row[$column].($column === 'rate' ? '%' : '') : __('hr.foundation.tax_brackets.no_upper_limit') }}
                                                        </div>
                                                    @else
                                                        <input class="form-control text-center" id="tax-bracket-{{ $index }}-{{ $column }}" name="tax_brackets[{{ $index }}][{{ $column }}]" type="number" min="0" step="{{ $column === 'rate' ? '0.0001' : '0.01' }}" value="{{ $row[$column] ?? '' }}" @required($column !== 'to_amount')>
                                                        @if ($column === 'to_amount')
                                                            <div class="form-text">{{ __('hr.foundation.tax_brackets.no_upper_limit_help') }}</div>
                                                        @endif
                                                        <div class="invalid-feedback" data-error-for="tax_brackets.{{ $index }}.{{ $column }}"></div>
                                                    @endif
                                                </div>
                                            @endforeach
                                            <div class="col-12">
                                                <label class="form-label" for="tax-bracket-{{ $index }}-notes">{{ __('common.fields.notes') }}</label>
                                                @if ($isView)
                                                    <div class="form-control-plaintext" id="tax-bracket-{{ $index }}-notes">{{ ($row['notes'] ?? '') ?: '—' }}</div>
                                                @else
                                                    <input class="form-control" id="tax-bracket-{{ $index }}-notes" name="tax_brackets[{{ $index }}][notes]" type="text" value="{{ $row['notes'] ?? '' }}">
                                                    <div class="invalid-feedback" data-error-for="tax_brackets.{{ $index }}.notes"></div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="invalid-feedback d-block" data-error-for="tax_brackets"></div>
                        </div>
                    @endif

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
            'taxBracketMinimum' => __('hr.foundation.tax_brackets.minimum'),
            'insuranceComponentMinimum' => __('hr.foundation.insurance_components.minimum'),
            'taxBracketSequence' => __('hr.foundation.tax_brackets.sequence'),
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
