@if ($isView)
    <x-forms.view-field :for="$inputId" :label="$fieldLabel" :value="$selectedText !== '' ? $selectedText : null" />
@else
    @php
        $inlineMergeFields = $inlineMergeFields ?? [];
        $required = (bool) ($required ?? false);
        $createUrl = $createUrl ?? null;
    @endphp
    <x-forms.label :for="$inputId" :label="$fieldLabel" :required="$required" />
    <div class="hr-select2-inline-control">
        <select id="{{ $inputId }}"
            name="{{ $fieldName }}"
            class="form-select js-select2-ajax"
            data-url="{{ $dataUrl }}"
            data-placeholder="{{ $placeholder }}"
            data-allow-clear="true"
            @required($required)>
            @if ($selectedValue !== '' && $selectedText !== '')
                <option value="{{ $selectedValue }}" selected>{{ $selectedText }}</option>
            @endif
        </select>
        @if ($canCreate && $createUrl)
            <a class="btn btn-falcon-default btn-sm" href="{{ $createUrl }}" target="_blank" rel="noopener" title="{{ __('hr.inline_lookup.add_new') }}" data-bs-title="{{ __('hr.inline_lookup.add_new') }}">
                <span class="fas fa-plus"></span>
                <span class="visually-hidden">{{ __('hr.inline_lookup.add_new') }}</span>
            </a>
        @elseif ($canCreate && $inlineUrl)
            <button class="btn btn-falcon-default btn-sm js-inline-lookup-create" type="button" data-target-select="#{{ $inputId }}" data-url="{{ $inlineUrl }}" data-label="{{ $fieldLabel }}" title="{{ __('hr.inline_lookup.add_button', ['label' => $fieldLabel]) }}" @if ($inlineMergeFields !== []) data-inline-merge="{{ e(json_encode($inlineMergeFields, JSON_UNESCAPED_UNICODE)) }}" @endif>
                <span class="fas fa-plus"></span>
                <span class="visually-hidden">{{ __('hr.inline_lookup.add_button', ['label' => $fieldLabel]) }}</span>
            </button>
        @endif
    </div>
    <div class="invalid-feedback d-block" data-error-for="{{ $fieldName }}"></div>
@endif
