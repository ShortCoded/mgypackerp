@php
    $mapping = $mapping ?? null;
    $device = $mapping?->device;
    $deviceText = $device ? trim(implode(' / ', array_filter([$device->name, $device->doc_num]))) : '';
    $mappingIndex = (string) $biometricIndex;
@endphp

<div class="border rounded-3 p-3 js-hr-biometric-row" data-biometric-index="{{ $mappingIndex }}">
    @unless ($isView)
        @if ($mapping)
            <x-forms.input type="hidden" name="biometric_mappings[{{ $mappingIndex }}][id]" value="{{ $mapping->id }}" />
        @endif
        <x-forms.input type="hidden" name="biometric_mappings[{{ $mappingIndex }}][_delete]" value="0" class="js-hr-biometric-delete-flag" />
    @endunless

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="mb-0 js-hr-biometric-card-title">{{ __('hr.employees.biometric.item_title', ['number' => is_numeric($mappingIndex) ? ((int) $mappingIndex + 1) : ':number']) }}</h6>
        @unless ($isView)
            <button type="button" class="btn btn-sm btn-outline-danger js-hr-biometric-remove" title="{{ __('common.actions.delete') }}">
                <span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}
            </button>
        @endunless
    </div>

    <div class="row g-3 align-items-start">
        <div class="col-md-6">
            @if ($isView)
                <x-forms.view-field :for="'hr-biometric-device-'.$mappingIndex" :label="__('hr.employees.biometric.device')" :value="$deviceText" />
            @else
                <label class="form-label" for="hr-biometric-device-{{ $mappingIndex }}">{{ __('hr.employees.biometric.device') }}</label>
                <div class="hr-select2-inline-control">
                    <x-forms.select id="hr-biometric-device-{{ $mappingIndex }}" name="biometric_mappings[{{ $mappingIndex }}][device_doc_num]" class="form-select js-select2-ajax" data-url="{{ $biometricDeviceSelect['url'] }}" data-placeholder="{{ __('hr.employees.biometric.placeholder_device') }}" data-allow-clear="true">
                        @if ($device)
                            <option value="{{ $device->doc_num }}" selected>{{ $deviceText }}</option>
                        @endif
                    </x-forms.select>
                    @if (($biometricDeviceSelect['can_create'] ?? false) && ($biometricDeviceSelect['create_url'] ?? null))
                        <a class="btn btn-falcon-default btn-sm" href="{{ $biometricDeviceSelect['create_url'] }}" target="_blank" rel="noopener" title="{{ __('hr.inline_lookup.add_new_device') }}" data-bs-title="{{ __('hr.inline_lookup.add_new_device') }}">
                            <span class="fas fa-plus"></span><span class="visually-hidden">{{ __('hr.inline_lookup.add_new_device') }}</span>
                        </a>
                    @endif
                </div>
                <div class="invalid-feedback d-block" data-error-for="biometric_mappings.{{ $mappingIndex }}.device_doc_num"></div>
            @endif
        </div>

        <div class="col-md-4">
            <label class="form-label" for="hr-biometric-code-{{ $mappingIndex }}">{{ __('hr.employees.biometric.code') }}</label>
            @if ($isView)
                <x-forms.view-field :for="'hr-biometric-code-'.$mappingIndex" :value="$mapping?->biometric_code" dir="ltr" />
            @else
                <x-forms.input id="hr-biometric-code-{{ $mappingIndex }}" name="biometric_mappings[{{ $mappingIndex }}][biometric_code]" type="text" class="form-control" value="{{ $mapping?->biometric_code }}" placeholder="{{ __('hr.employees.biometric.code_placeholder') }}" dir="ltr" />
                <div class="invalid-feedback d-block" data-error-for="biometric_mappings.{{ $mappingIndex }}.biometric_code"></div>
            @endif
        </div>

        <div class="col-md-2">
            <label class="form-label d-block" for="hr-biometric-active-{{ $mappingIndex }}">{{ __('hr.employees.biometric.active') }}</label>
            @if ($isView)
                <span class="badge badge-soft-{{ $mapping?->is_active ? 'success' : 'secondary' }}">{{ __('common.status.'.($mapping?->is_active ? 'active' : 'inactive')) }}</span>
            @else
                <div class="form-check form-switch pt-2">
                    <x-forms.input id="hr-biometric-active-{{ $mappingIndex }}" name="biometric_mappings[{{ $mappingIndex }}][is_active]" type="checkbox" class="form-check-input" value="1" :checked='$mapping?->is_active ?? true' />
                </div>
            @endif
        </div>

        <div class="col-12">
            <label class="form-label" for="hr-biometric-notes-{{ $mappingIndex }}">{{ __('hr.employees.biometric.notes') }}</label>
            @if ($isView)
                <x-forms.view-field :for="'hr-biometric-notes-'.$mappingIndex" :value="$mapping?->notes" />
            @else
                <x-forms.input id="hr-biometric-notes-{{ $mappingIndex }}" name="biometric_mappings[{{ $mappingIndex }}][notes]" type="text" class="form-control" value="{{ $mapping?->notes }}" placeholder="{{ __('hr.employees.biometric.notes_placeholder') }}" />
            @endif
        </div>
    </div>
</div>
