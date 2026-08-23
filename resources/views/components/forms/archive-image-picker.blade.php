@props([
    'fieldId',
    'fieldName',
    'value' => '',
    'file' => null,
    'isView' => false,
    'label',
    'existingLabel',
    'emptyLabel',
    'helpText',
    'selectTitle',
    'selectLabel',
    'removeLabel',
    'collection',
    'icon' => 'fas fa-image',
])

@php
    $selectedDocNum = trim((string) $value);
    $hasPreview = $selectedDocNum !== '' && $file?->doc_num === $selectedDocNum;
    $previewUrl = $hasPreview ? route('admin.file-manager.files.preview', $file->doc_num) : '';
    $fileLabel = $hasPreview ? ($file?->original_name ?? $existingLabel) : $emptyLabel;
    $pickerButtonId = $fieldId.'-picker-button';
@endphp

<div {{ $attributes->class(['archive-image-picker']) }}>
    <x-forms.label :for="$isView ? $fieldId.'-preview' : $pickerButtonId" :label="$label" />

    @unless ($isView)
        <input
            type="hidden"
            id="{{ $fieldId }}"
            name="{{ $fieldName }}"
            value="{{ $selectedDocNum }}"
            class="js-archive-image-picker-input">
    @endunless

    <div
        id="{{ $fieldId }}-field"
        class="p-3 border rounded-2 bg-body-tertiary archive-image-picker-panel js-archive-image-picker-field @if ($isView) opacity-75 @endif"
        data-current-url="{{ $previewUrl }}"
        data-existing-url="{{ $previewUrl }}"
        data-existing-label="{{ $existingLabel }}"
        data-empty-label="{{ $emptyLabel }}"
        data-field-name="{{ $fieldName }}">
        <div class="d-flex flex-column flex-lg-row align-items-start gap-3">
            <div id="{{ $fieldId }}-preview" class="overflow-hidden bg-white border d-flex align-items-center justify-content-center rounded-2 flex-shrink-0 archive-image-preview-frame">
                <img
                    class="w-100 h-100 object-fit-contain js-archive-image-preview @if (! $hasPreview) d-none @endif"
                    @if ($previewUrl !== '') src="{{ $previewUrl }}" @endif
                    alt="{{ $label }}">
                <span class="{{ $icon }} text-400 fs-5 js-archive-image-placeholder @if ($hasPreview) d-none @endif"></span>
            </div>

            <div class="flex-1 min-w-0">
                <div class="fw-semibold text-break js-archive-image-file-name">{{ $fileLabel }}</div>
                <div class="mt-1 small text-600">{{ $helpText }}</div>

                @unless ($isView)
                    @can('file_manager.view')
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <button
                                type="button"
                                id="{{ $pickerButtonId }}"
                                class="btn btn-falcon-primary btn-sm js-archive-image-picker-trigger"
                                data-file-picker
                                data-picker-accept="image"
                                data-picker-max="1"
                                data-picker-title="{{ $selectTitle }}"
                                data-picker-target-input="#{{ $fieldId }}"
                                data-picker-uploader="#{{ $fieldId }}-field"
                                data-picker-collection="{{ $collection }}"
                                data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"
                                data-picker-allow-create-folder="{{ auth()->user()?->can('file_manager.folders.create') ? 'true' : 'false' }}">
                                <span class="fas fa-images me-1"></span>{{ $selectLabel }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-falcon-default btn-sm text-danger js-archive-image-remove @if (! $hasPreview) d-none @endif">
                                <span class="fas fa-times me-1"></span>{{ $removeLabel }}
                            </button>
                        </div>
                    @endcan
                @endunless
            </div>
        </div>
    </div>

    <div class="invalid-feedback d-block" data-error-for="{{ $fieldName }}"></div>
</div>
