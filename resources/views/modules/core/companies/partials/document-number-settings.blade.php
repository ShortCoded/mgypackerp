@can('companies.document_number_settings.update')
    <div class="card mb-3">
        <div class="card-header py-2">
            <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#companies-document-number-settings"
                aria-expanded="false"
                aria-controls="companies-document-number-settings">
                <span class="fw-semibold">{{ __('companies.document_number_settings.title') }}</span>
                <span class="fas fa-chevron-down fs-11"></span>
            </button>
        </div>
        <div class="collapse" id="companies-document-number-settings">
            <div class="card-body">
                <p class="text-700 mb-3">{{ __('companies.document_number_settings.description') }}</p>
                <form class="js-company-document-number-settings-form"
                    action="{{ route('admin.companies.document-number-settings.update') }}"
                    method="POST"
                    novalidate>
                    @csrf
                    @method('PUT')
                    <div class="alert alert-danger alert-dismissible fade show d-none js-company-alert" role="alert">
                        <span class="js-company-alert-message"></span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                    </div>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label" for="companies-document-prefix">{{ __('companies.document_number_settings.prefix') }}</label>
                            <input class="form-control" id="companies-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}">
                            <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                        </div>
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="companies-document-padding">{{ __('companies.document_number_settings.padding') }}</label>
                            <input class="form-control" id="companies-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 0 }}" required>
                            <div class="invalid-feedback d-block" data-error-for="padding"></div>
                        </div>
                        <div class="col-md-auto">
                            <button type="submit" class="btn btn-falcon-primary">
                                <span class="fas fa-save me-1"></span>{{ __('companies.document_number_settings.save') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
