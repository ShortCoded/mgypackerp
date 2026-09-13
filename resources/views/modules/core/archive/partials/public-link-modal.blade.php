<div class="modal fade" id="archive-public-link-modal" tabindex="-1" aria-labelledby="archive-public-link-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="archive-public-link-modal-label">{{ __('archive.public_links.title') }}</h5>
                    <p class="mb-0 fs-10 text-600 js-archive-public-link-item"></p>
                </div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info d-none js-archive-public-link-alert" role="alert"></div>
                <div class="mb-3 js-archive-public-link-url-wrap d-none">
                    <label class="form-label" for="archive-public-link-url">{{ __('archive.public_links.public_url') }}</label>
                    <div class="input-group">
                        <x-forms.input id="archive-public-link-url" class="form-control js-archive-public-link-url" type="text" readonly />
                        <button class="btn btn-falcon-default js-archive-public-link-copy" type="button">
                            <span class="far fa-copy me-1"></span>{{ __('archive.public_links.copy') }}
                        </button>
                        <a class="btn btn-falcon-primary js-archive-public-link-open" href="#" target="_blank" rel="noopener">
                            <span class="fas fa-external-link-alt me-1"></span>{{ __('archive.public_links.open') }}
                        </a>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge rounded-pill badge-subtle-success js-archive-public-link-preview-badge d-none">{{ __('archive.public_links.preview_allowed') }}</span>
                    <span class="badge rounded-pill badge-subtle-secondary js-archive-public-link-download-badge d-none">{{ __('archive.public_links.download_disabled') }}</span>
                    <span class="badge rounded-pill badge-subtle-info js-archive-public-link-download-enabled-badge d-none">{{ __('archive.public_links.download_allowed') }}</span>
                </div>
                <div class="form-check form-switch mt-3 js-archive-public-link-download-setting">
                    <x-forms.input class="form-check-input js-archive-public-link-allow-download" id="archive-public-link-allow-download" type="checkbox" />
                    <label class="form-check-label" for="archive-public-link-allow-download">{{ __('archive.public_links.allow_download_label') }}</label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-falcon-primary d-none js-archive-public-link-modal-create" type="button">
                    <span class="fas fa-link me-1"></span>
                    <span class="js-archive-public-link-create-label">{{ __('archive.public_links.create') }}</span>
                    <span class="js-archive-public-link-save-label d-none">{{ __('archive.public_links.save_settings') }}</span>
                </button>
                <button class="btn btn-falcon-danger d-none js-archive-public-link-revoke" type="button">
                    <span class="fas fa-unlink me-1"></span>{{ __('archive.public_links.revoke') }}
                </button>
                <button class="btn btn-falcon-default" type="button" data-bs-dismiss="modal">{{ __('common.actions.close') }}</button>
            </div>
        </div>
    </div>
</div>
