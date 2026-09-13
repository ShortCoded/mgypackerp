@if ($isView)
    <x-forms.view-field :for="$inputId" :label="$fieldLabel" :value="$selectedText !== '' ? $selectedText : null" />
@else
    @php
        $inlineMergeFields = $inlineMergeFields ?? [];
        $required = (bool) ($required ?? false);
        $createUrl = $createUrl ?? null;
        $dependsOn = $dependsOn ?? null;
        $dependentParam = $dependentParam ?? null;
        $dependentResultField = $dependentResultField ?? null;
        $preserveDependentValues = (bool) ($preserveDependentValues ?? false);
        $disableWhenDependencyEmpty = (bool) ($disableWhenDependencyEmpty ?? false);
    @endphp
    <x-forms.label :for="$inputId" :label="$fieldLabel" :required="$required" />
    <div class="hr-select2-inline-control">
        <x-forms.select :id="$inputId"
            :name="$fieldName"
            variant="ajax"
            :url="$dataUrl"
            :placeholder="$placeholder"
            :data-depends-on="$dependsOn"
            :data-dependent-param="$dependentParam"
            :data-dependent-result-field="$dependentResultField"
            :data-preserve-dependent-values="$preserveDependentValues ? 'true' : null"
            :data-disable-when-dependency-empty="$disableWhenDependencyEmpty ? 'true' : null"
            :required="$required">
            @if ($selectedValue !== '' && $selectedText !== '')
                <option value="{{ $selectedValue }}" selected>{{ $selectedText }}</option>
            @endif
        </x-forms.select>
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
