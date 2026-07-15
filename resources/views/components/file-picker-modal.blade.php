@props([
    'id' => 'file-picker-modal',
    'title' => __('archive.picker.select_image'),
])

@php
    $acceptedImageFiles = collect(config('archive.logo.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp']))
        ->map(fn (string $extension): string => '.' . ltrim($extension, '.'))
        ->implode(',');
    $acceptedDocumentFiles = collect(config('archive.documents.allowed_extensions', config('archive.allowed_extensions', [])))
        ->map(fn (string $extension): string => '.' . ltrim($extension, '.'))
        ->implode(',');
@endphp

@once
    @push('styles')
        <style>
            .file-picker-modal .modal-dialog {
                max-width: min(1180px, calc(100vw - 1rem));
            }

            .file-picker-modal .modal-content {
                min-height: min(760px, calc(100vh - 2rem));
            }

            .file-picker-modal .file-picker-main {
                min-height: 22rem;
            }

            .file-picker-modal .file-picker-header-title {
                flex: 1 1 18rem;
                min-width: 0;
            }

            .file-picker-modal .file-picker-header-tools {
                flex: 0 1 min(26rem, 100%);
                min-width: min(100%, 18rem);
            }

            .file-picker-modal .file-picker-search {
                min-width: 0;
            }

            [dir="rtl"] .file-picker-modal .file-picker-header-tools {
                margin-right: auto !important;
                margin-left: 0 !important;
            }

            .file-picker-modal .file-picker-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr));
                gap: .75rem;
            }

            .file-picker-modal .file-picker-card {
                min-width: 0;
                min-height: 9.25rem;
                width: 100%;
                border: 1px solid var(--falcon-border-color, #d8e2ef);
                border-radius: .5rem;
                background: var(--falcon-white, #fff);
                color: inherit;
                text-align: start;
                transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
            }

            .file-picker-modal .file-picker-card:hover,
            .file-picker-modal .file-picker-card:focus-visible,
            .file-picker-modal .file-picker-card.is-selected {
                border-color: var(--falcon-primary, #2c7be5);
                box-shadow: 0 .25rem .75rem rgba(44, 123, 229, .14);
            }

            .file-picker-modal .file-picker-card:focus-visible {
                outline: 0;
            }

            .file-picker-modal .file-picker-thumb {
                height: 6.5rem;
                background: var(--falcon-gray-100, #f9fafd);
            }

            .file-picker-modal .file-picker-thumb img {
                display: block;
                width: 100%;
                height: 100%;
                object-fit: cover;
            }

            .file-picker-modal .file-picker-folder-icon {
                font-size: 2.5rem;
            }

            .file-picker-modal .file-picker-delete-button {
                z-index: 2;
                width: 2rem;
                height: 2rem;
            }

            @media (max-width: 575.98px) {
                .file-picker-modal .modal-dialog {
                    max-width: 100%;
                    margin: .25rem;
                }

                .file-picker-modal .modal-content {
                    min-height: calc(100vh - .5rem);
                }

                .file-picker-modal .file-picker-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                    gap: .5rem;
                }

                .file-picker-modal .file-picker-header-title,
                .file-picker-modal .file-picker-header-tools {
                    flex-basis: 100%;
                }
            }
        </style>
    @endpush
@endonce

<div class="modal fade file-picker-modal js-file-picker-modal"
    id="{{ $id }}"
    tabindex="-1"
    aria-labelledby="{{ $id }}-title"
    aria-hidden="true"
    data-list-url="{{ route('admin.file-manager.picker.items') }}"
    data-upload-url="{{ route('admin.file-manager.picker.files.store') }}"
    data-folder-store-url="{{ route('admin.file-manager.picker.folders.store') }}"
    data-file-url-template="{{ route('admin.file-manager.picker.files.show', ['file' => '__PUBLIC_ID__']) }}"
    data-cannot-select-message="{{ __('archive.picker.cannot_select') }}"
    data-delete-confirm-title="{{ __('archive.messages.delete_confirm_title') }}"
    data-delete-confirm-text="{{ __('archive.messages.delete_confirm_text') }}"
    data-delete-confirm-yes="{{ __('archive.messages.delete_confirm_yes') }}"
    data-delete-confirm-no="{{ __('common.actions.no') }}"
    data-upload-image-label="{{ __('archive.picker.upload_image') }}"
    data-upload-file-label="{{ __('archive.picker.upload_file') }}"
    data-choose-image-label="{{ __('archive.picker.choose_image') }}"
    data-choose-file-label="{{ __('archive.picker.choose_file') }}"
    data-empty-images-label="{{ __('archive.picker.empty_images') }}"
    data-empty-files-label="{{ __('archive.picker.empty_files') }}"
    data-accepted-image-files="{{ $acceptedImageFiles }}"
    data-accepted-document-files="{{ $acceptedDocumentFiles }}"
    data-unexpected-error-message="{{ __('common.messages.unexpected_error') }}">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header align-items-start gap-3 flex-wrap">
                <div class="file-picker-header-title">
                    <h5 class="modal-title js-file-picker-title" id="{{ $id }}-title">{{ $title }}</h5>
                    <nav aria-label="{{ __('archive.folders') }}" class="mt-2">
                        <ol class="breadcrumb mb-0 js-file-picker-breadcrumb" style="--falcon-breadcrumb-divider: '/';"></ol>
                    </nav>
                </div>
                <div class="file-picker-header-tools ms-auto d-flex align-items-start gap-2">
                    <form class="file-picker-search js-file-picker-search-form flex-grow-1" novalidate>
                        <label class="visually-hidden" for="{{ $id }}-search">{{ __('archive.picker.search_placeholder') }}</label>
                        <div class="search-box">
                            <input class="form-control search-input js-file-picker-search-input"
                                id="{{ $id }}-search"
                                type="search"
                                autocomplete="off"
                                placeholder="{{ __('archive.picker.search_placeholder') }}"
                                aria-label="{{ __('archive.picker.search_placeholder') }}">
                            <span class="fas fa-search search-box-icon"></span>
                        </div>
                    </form>
                    <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                </div>
            </div>

            <div class="modal-body p-0">
                <div class="border-bottom bg-body-tertiary px-3 py-3">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button type="button" class="btn btn-falcon-default btn-sm js-file-picker-upload-button">
                            <span class="fas fa-cloud-upload-alt me-1"></span><span class="js-file-picker-upload-label">{{ __('archive.picker.upload_image') }}</span>
                        </button>
                        <input type="file" class="visually-hidden js-file-picker-upload-input" accept="{{ $acceptedImageFiles }}">

                        <button type="button" class="btn btn-falcon-default btn-sm js-file-picker-create-folder-toggle">
                            <span class="fas fa-folder-plus me-1"></span>{{ __('archive.picker.create_folder') }}
                        </button>

                        <form class="d-none align-items-center gap-2 js-file-picker-create-folder-form" novalidate>
                            <label class="visually-hidden" for="{{ $id }}-folder-name">{{ __('archive.picker.folder_name') }}</label>
                            <input class="form-control form-control-sm w-auto js-file-picker-folder-name"
                                id="{{ $id }}-folder-name"
                                name="name"
                                type="text"
                                maxlength="255"
                                placeholder="{{ __('archive.picker.folder_name') }}">
                            <button type="submit" class="btn btn-primary btn-sm">{{ __('common.actions.save') }}</button>
                        </form>
                    </div>
                </div>

                <div class="file-picker-main position-relative p-3">
                    <div class="alert alert-danger d-none js-file-picker-alert" role="alert"></div>

                    <div class="text-center text-600 py-5 d-none js-file-picker-loading">
                        <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
                        {{ __('archive.picker.loading') }}
                    </div>

                    <div class="file-picker-grid js-file-picker-grid"></div>

                    <div class="text-center text-600 py-5 d-none js-file-picker-empty">
                        <span class="fas fa-images text-400 fs-4" aria-hidden="true"></span>
                        <div class="mt-2 js-file-picker-empty-label">{{ __('archive.picker.empty_images') }}</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-falcon-default" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                <button type="button" class="btn btn-primary js-file-picker-select" disabled>
                    <span class="fas fa-check me-1"></span><span class="js-file-picker-select-label">{{ __('archive.picker.choose_image') }}</span>
                </button>
            </div>
        </div>
    </div>
</div>
