@can('file_manager.document_number_settings.update')
    <div class="card mb-3">
        <div class="card-header py-2">
            <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#archive-document-number-settings"
                aria-expanded="false"
                aria-controls="archive-document-number-settings">
                <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                <span class="fas fa-chevron-down fs-11"></span>
            </button>
        </div>
        <div class="collapse" id="archive-document-number-settings">
            <div class="card-body">
                <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                <form class="js-archive-document-number-settings-form"
                    action="{{ route('admin.file-manager.document-number-settings.update') }}"
                    method="POST"
                    novalidate>
                    @csrf
                    @method('PUT')
                    <div class="alert alert-danger alert-dismissible fade show d-none js-archive-document-number-settings-alert" role="alert">
                        <span class="js-archive-document-number-settings-alert-message"></span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                    </div>
                    <div class="row g-3 align-items-end">
                        <div class="col-12">
                            <div class="fw-semibold text-900">{{ __('archive.files') }}</div>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label" for="archive-files-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                            <x-forms.input class="form-control" id="archive-files-document-prefix" name="archive_files_prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['archive_files']['prefix'] ?? '' }}" />
                            <div class="invalid-feedback d-block" data-error-for="archive_files_prefix"></div>
                        </div>
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="archive-files-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                            <x-forms.input class="form-control" id="archive-files-document-padding" name="archive_files_padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['archive_files']['padding'] ?? 0 }}" required />
                            <div class="invalid-feedback d-block" data-error-for="archive_files_padding"></div>
                        </div>
                        <div class="col-12">
                            <div class="fw-semibold text-900">{{ __('archive.folders') }}</div>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label" for="archive-folders-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                            <x-forms.input class="form-control" id="archive-folders-document-prefix" name="archive_folders_prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['archive_folders']['prefix'] ?? '' }}" />
                            <div class="invalid-feedback d-block" data-error-for="archive_folders_prefix"></div>
                        </div>
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="archive-folders-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                            <x-forms.input class="form-control" id="archive-folders-document-padding" name="archive_folders_padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['archive_folders']['padding'] ?? 0 }}" required />
                            <div class="invalid-feedback d-block" data-error-for="archive_folders_padding"></div>
                        </div>
                        <div class="col-md-auto">
                            <button type="submit" class="btn btn-falcon-primary">
                                <span class="fas fa-save me-1"></span>{{ __('common.document_number_settings.save') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
