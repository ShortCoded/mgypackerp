@php
    $fieldModes = $field['modes'] ?? ['create', 'view', 'edit', 'clone'];
    $fieldPermission = $field['permission'] ?? null;
    $fieldVisible = in_array($mode, $fieldModes, true)
        && (! is_string($fieldPermission) || $fieldPermission === '' || auth()->user()?->can($fieldPermission));
    $fieldName = ($namePrefix ?? '') !== ''
        ? $namePrefix.'['.$field['name'].']'
        : $field['name'];
    $fieldId = preg_replace('/[^A-Za-z0-9_-]/', '-', ($fieldIdPrefix ?? 'erp-ui').'-'.$field['name']);
    $fieldType = $field['type'] ?? 'text';
    $fieldValue = $field['default_visual'] ?? '';
    $fieldReadonly = $isReadonly || (bool) ($field['readonly'] ?? false) || $fieldType === 'readonly_calculated';
    $fieldDisabled = (bool) ($field['disabled'] ?? false);
    $fieldDirection = $field['direction'] ?? null;
    $fieldOptions = $field['options'] ?? [];

    if ($field['name'] === 'doc_num' && $docNum && $mode !== 'clone') {
        $fieldValue = $docNum;
    }

    $inputType = match ($fieldType) {
        'number' => 'number',
        'decimal', 'money', 'percentage' => 'number',
        'date', 'datetime' => 'text',
        'time' => 'time',
        'color' => 'color',
        default => 'text',
    };
    $step = match ($fieldType) {
        'number' => '1',
        'percentage' => '0.01',
        'decimal', 'money' => '0.0001',
        default => null,
    };
@endphp

@if ($fieldVisible)
    @if ($fieldType === 'hidden')
        <input id="{{ $fieldId }}" name="{{ $fieldName }}" type="hidden" value="{{ $fieldValue }}">
    @elseif (in_array($fieldType, ['separator', 'title'], true))
        <div class="col-12">
            <div class="border-bottom pb-1 fw-semibold text-700">{{ $definition->localized($field['label']) }}</div>
        </div>
    @elseif ($fieldType === 'alert')
        <div class="col-12">
            <div class="alert alert-subtle-info mb-0" role="status">
                <span class="fas fa-info-circle me-1"></span>{{ __('erp_ui_shell.messages.history_empty') }}
            </div>
        </div>
    @elseif ($fieldType === 'attachment_list')
        <div class="col-12">
            <x-forms.label :for="$fieldId" :label="$definition->localized($field['label'])" />
            <div id="{{ $fieldId }}" class="erp-ui-shell-empty-panel text-center text-600 py-4">
                <span class="fas fa-paperclip d-block fs-5 mb-2"></span>
                {{ __('erp_ui_shell.attachments.empty') }}
            </div>
        </div>
    @else
        <div class="col-12 col-md-{{ min(12, max(1, (int) ($field['width'] ?? 4))) }} {{ $field['class'] ?? '' }}"
            @if (! empty($field['visible_when'])) data-visible-when='@json($field['visible_when'])' @endif>
            <x-forms.label :for="$fieldId" :label="$definition->localized($field['label'])" :required="(bool) ($field['required'] ?? false)" />

            @if ($fieldReadonly)
                @if (in_array($fieldType, ['textarea', 'rich_text', 'summernote'], true))
                    <x-forms.view-field :for="$fieldId" as="textarea" :value="$fieldValue" :dir="$fieldDirection" />
                @else
                    <x-forms.view-field :for="$fieldId" :value="$fieldValue" :dir="$fieldDirection" />
                @endif
            @elseif (in_array($fieldType, ['textarea', 'rich_text', 'summernote'], true))
                <textarea id="{{ $fieldId }}"
                    name="{{ $fieldName }}"
                    rows="{{ $fieldType === 'textarea' ? 3 : 6 }}"
                    class="form-control @if (in_array($fieldType, ['rich_text', 'summernote'], true)) js-erp-ui-summernote @endif"
                    placeholder="{{ $definition->localized($field['placeholder'] ?? null) }}"
                    @if ($fieldDirection) dir="{{ $fieldDirection }}" @endif
                    @disabled($fieldDisabled)>{{ $fieldValue }}</textarea>
            @elseif (in_array($fieldType, ['static_select', 'select', 'status', 'multi_select', 'empty_select2', 'ajax_select2'], true))
                @php
                    $selectClass = match ($fieldType) {
                        'empty_select2' => 'js-select2-local',
                        'ajax_select2' => 'js-select2-ajax',
                        default => '',
                    };
                    $endpoint = $field['endpoint'] ?? null;
                    $endpointUrl = is_string($endpoint) && \Illuminate\Support\Facades\Route::has($endpoint) ? route($endpoint) : null;
                @endphp
                <select id="{{ $fieldId }}"
                    name="{{ $fieldName }}@if ($fieldType === 'multi_select')[]@endif"
                    class="form-select {{ $selectClass }}"
                    data-placeholder="{{ $definition->localized($field['placeholder'] ?? null) }}"
                    data-allow-clear="true"
                    @if ($endpointUrl) data-url="{{ $endpointUrl }}" @endif
                    @if ($fieldType === 'multi_select') multiple @endif
                    @disabled($fieldDisabled)>
                    @unless ($fieldType === 'multi_select')
                        <option value=""></option>
                    @endunless
                    @foreach ($fieldOptions as $option)
                        <option value="{{ $option['value'] ?? '' }}" @selected((string) $fieldValue === (string) ($option['value'] ?? ''))>
                            {{ $definition->localized($option['label'] ?? null) }}
                        </option>
                    @endforeach
                </select>
            @elseif ($fieldType === 'radio')
                <div id="{{ $fieldId }}" class="d-flex flex-wrap align-items-center gap-3 min-h-control">
                    @foreach ($fieldOptions as $optionIndex => $option)
                        <div class="form-check mb-0">
                            <input class="form-check-input" id="{{ $fieldId }}-{{ $optionIndex }}" name="{{ $fieldName }}" type="radio" value="{{ $option['value'] ?? '' }}" @checked((string) $fieldValue === (string) ($option['value'] ?? ''))>
                            <label class="form-check-label" for="{{ $fieldId }}-{{ $optionIndex }}">{{ $definition->localized($option['label'] ?? null) }}</label>
                        </div>
                    @endforeach
                </div>
            @elseif (in_array($fieldType, ['switch', 'checkbox'], true))
                <div class="form-check @if ($fieldType === 'switch') form-switch @endif min-h-control d-flex align-items-center gap-2">
                    <input class="form-check-input" id="{{ $fieldId }}" name="{{ $fieldName }}" type="checkbox" value="1" @checked((bool) $fieldValue) @disabled($fieldDisabled)>
                    <label class="form-check-label" for="{{ $fieldId }}">{{ __('erp_ui_shell.fields.enabled') }}</label>
                </div>
            @elseif (in_array($fieldType, ['file_picker', 'file', 'image'], true))
                <input id="{{ $fieldId }}" name="{{ $fieldName }}" type="file" class="form-control" @if ($fieldType === 'image') accept="image/*" @endif @disabled($fieldDisabled)>
            @else
                <div class="input-group">
                    @if (! empty($field['prefix']))
                        <span class="input-group-text">{{ $field['prefix'] }}</span>
                    @endif
                    <input id="{{ $fieldId }}"
                        name="{{ $fieldName }}"
                        type="{{ $inputType }}"
                        class="form-control @if (in_array($fieldType, ['date', 'datetime'], true)) js-date-picker @endif @if (in_array($fieldType, ['number', 'decimal', 'money', 'percentage', 'document_number'], true)) text-center @endif"
                        value="{{ $fieldValue }}"
                        placeholder="{{ $definition->localized($field['placeholder'] ?? null) }}"
                        @if ($step) step="{{ $step }}" @endif
                        @if ($fieldType === 'datetime') data-enable-time="true" data-time-24hr="true" @endif
                        @if (in_array($fieldType, ['date', 'datetime'], true)) data-locale="{{ app()->getLocale() }}" @endif
                        @if ($fieldDirection) dir="{{ $fieldDirection }}" @endif
                        @if ($fieldType === 'document_number' && $docNum) readonly @endif
                        @disabled($fieldDisabled)>
                    @if (! empty($field['suffix']))
                        <span class="input-group-text">{{ $field['suffix'] }}</span>
                    @endif
                </div>
            @endif

            @if (! empty($field['help']))
                <div class="form-text">{{ $definition->localized($field['help']) }}</div>
            @endif
        </div>
    @endif
@endif
