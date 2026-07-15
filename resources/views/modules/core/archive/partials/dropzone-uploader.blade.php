@props([
    'uploadUrl',
    'listUrl' => null,
    'maxFiles' => (int) config('archive.uploads.max_files', 100),
    'maxFileSize' => (int) config('archive.uploads.max_file_size_mib', 50),
    'parallelUploads' => (int) config('archive.uploads.parallel_uploads', 2),
    'acceptedExtensions' => config('archive.documents.allowed_extensions', config('archive.allowed_extensions', [])),
])

@php
    $acceptedFiles = collect($acceptedExtensions)->map(fn (string $extension): string => '.' . ltrim($extension, '.'))->implode(',');
@endphp

<form class="dropzone dropzone-multiple p-0 js-archive-dropzone"
      data-upload-url="{{ $uploadUrl }}"
      @if ($listUrl)
          data-list-url="{{ $listUrl }}"
      @endif
      data-max-files="{{ $maxFiles }}"
      data-max-filesize="{{ $maxFileSize }}"
      data-parallel-uploads="{{ $parallelUploads }}"
      data-accepted-files="{{ $acceptedFiles }}"
      data-too-many-files-message="{{ __('archive.too_many_files', ['count' => $maxFiles]) }}"
      data-file-too-large-message="{{ __('archive.file_too_large', ['size' => $maxFileSize]) }}"
      data-invalid-file-type-message="{{ __('archive.invalid_file_type') }}"
      data-shortcut-action="file-manager.upload"
      title="{{ __('common.shortcuts.file_manager_upload') }}"
      data-bs-title="{{ __('common.shortcuts.file_manager_upload') }}"
      action="{{ $uploadUrl }}"
      method="POST"
      enctype="multipart/form-data">
    @csrf
    <div class="fallback"><input name="files[]" type="file" multiple></div>
    <div class="dz-message" data-dz-message="data-dz-message">
        <img class="me-2" src="{{ asset('assets/img/icons/cloud-upload.svg') }}" width="25" alt="">
        {{ __('archive.drop_files_here') }}
    </div>
    <template class="js-dropzone-preview-template">
        <div class="dz-preview dz-file-preview d-flex media mb-3 pb-3 border-bottom btn-reveal-trigger">
            <img class="dz-image" src="{{ asset('assets/img/generic/image-file-2.png') }}" alt="..." data-dz-thumbnail="data-dz-thumbnail">
            <div class="flex-1 d-flex flex-between-center">
                <div>
                    <h6 data-dz-name="data-dz-name"></h6>
                    <div class="d-flex align-items-center">
                        <p class="mb-0 fs-10 text-400 lh-1" data-dz-size="data-dz-size"></p>
                        <div class="dz-progress"><span class="dz-upload" data-dz-uploadprogress=""></span></div>
                    </div>
                    <span class="fs-11 text-danger" data-dz-errormessage="data-dz-errormessage"></span>
                </div>
                <div class="dropdown font-sans-serif">
                    <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal dropdown-caret-none" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="fas fa-ellipsis-h"></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end border py-2">
                        <a class="dropdown-item" href="#!" data-dz-remove="data-dz-remove">{{ __('common.actions.delete') }}</a>
                    </div>
                </div>
            </div>
        </div>
    </template>
    <div class="dz-preview-container dz-preview-multiple m-0 d-flex flex-column"></div>
</form>

<div class="form-text mt-2">
    {{ __('archive.max_files_hint', ['count' => $maxFiles]) }}
    {{ __('archive.max_file_size_hint', ['size' => $maxFileSize]) }}
</div>
