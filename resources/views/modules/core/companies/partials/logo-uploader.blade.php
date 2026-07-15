@props([
    'inputId',
    'inputName' => 'logo',
    'currentUrl' => null,
    'disabled' => false,
    'required' => false,
    'acceptedExtensions' => config('archive.logo.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp']),
    'maxFileSize' => (int) config('archive.logo.max_file_size_mib', 2),
    'existingLabel' => __('companies.logo.existing_file'),
    'emptyLabel' => __('companies.logo.no_file_selected'),
    'uploadLabel' => __('companies.archive.upload_logo'),
    'altLabel' => __('companies.fields.logo'),
    'helpText' => null,
    'invalidFileType' => __('archive.invalid_file_type'),
    'fileTooLarge' => null,
    'wrapperId' => null,
])

@php
    $acceptedFiles = collect($acceptedExtensions)->map(fn (string $extension): string => '.' . ltrim($extension, '.'))->implode(',');
@endphp

<div @if ($wrapperId) id="{{ $wrapperId }}" @endif
     class="border rounded-2 bg-body-tertiary p-3 js-company-logo-uploader @if ($disabled) opacity-75 @endif"
     data-current-url="{{ $currentUrl ?? '' }}"
     data-existing-label="{{ $existingLabel }}"
     data-no-file-label="{{ $emptyLabel }}"
     data-input-id="{{ $inputId }}"
     data-input-name="{{ $inputName }}"
     data-max-file-size="{{ $maxFileSize }}"
     data-accepted-files="{{ $acceptedFiles }}"
     data-invalid-file-type="{{ $invalidFileType }}"
     data-file-too-large="{{ $fileTooLarge ?? ($inputName === 'favicon' ? __('companies.validation.favicon_too_large', ['size' => $maxFileSize]) : __('archive.logo_file_too_large', ['size' => $maxFileSize])) }}">
    <input id="{{ $inputId }}"
           name="{{ $inputName }}"
           type="file"
           accept="{{ $acceptedFiles }}"
           class="visually-hidden js-company-logo-input"
           @required($required && ! $disabled)
           @disabled($disabled)>

    <div class="d-flex flex-column flex-md-row align-items-start gap-3">
        <div class="d-flex align-items-center justify-content-center bg-white border rounded-2 overflow-hidden flex-shrink-0" style="width: 8rem; height: 8rem;">
            <img class="h-100 w-100 object-fit-contain js-company-logo-preview-image @if (! $currentUrl) d-none @endif"
                 src="{{ $currentUrl ?? '' }}"
                 alt="{{ $altLabel }}">
            <span class="fas fa-image text-400 fs-5 js-company-logo-placeholder @if ($currentUrl) d-none @endif"></span>
        </div>

        <div class="flex-1">
            @unless ($disabled)
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <label class="btn btn-falcon-default btn-sm mb-0 js-company-logo-trigger" for="{{ $inputId }}">
                        <span class="fas fa-cloud-upload-alt me-1"></span>{{ $uploadLabel }}
                    </label>
                    <button type="button" class="btn btn-falcon-default btn-sm text-danger d-none js-company-logo-clear">
                        <span class="fas fa-times me-1"></span>{{ __('common.actions.clear') }}
                    </button>
                </div>
            @endunless

            <div class="fw-semibold js-company-logo-file-name">
                {{ $currentUrl ? $existingLabel : $emptyLabel }}
            </div>
            <div class="small text-600 mt-1">
                {{ $helpText ?? __('archive.max_logo_file_size_hint', ['size' => $maxFileSize]) }}
            </div>
            <div class="invalid-feedback d-block js-company-logo-error" data-logo-error></div>
        </div>
    </div>
</div>
