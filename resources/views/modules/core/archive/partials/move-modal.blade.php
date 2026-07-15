<div class="modal fade" id="archive-move-modal" tabindex="-1" aria-labelledby="archive-move-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="archive-move-modal-label">{{ __('archive.select_destination_folder') }}</h5>
                    <p class="mb-0 fs-10 text-600 js-archive-move-item-label"></p>
                </div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none js-archive-move-alert" role="alert"></div>
                <div class="mb-3">
                    <label class="form-label" for="archive-move-folder-search">{{ __('archive.search_folders') }}</label>
                    <div class="search-box">
                        <input id="archive-move-folder-search"
                            class="form-control search-input js-archive-move-folder-search"
                            type="search"
                            autocomplete="off"
                            placeholder="{{ __('archive.search_folders') }}">
                        <span class="fas fa-search search-box-icon"></span>
                    </div>
                </div>
                <div>
                    <label class="form-label" for="archive-move-destination-folder">{{ __('archive.destination_folder') }}</label>
                    <select id="archive-move-destination-folder" class="form-select js-archive-move-destination-folder"></select>
                    <div class="invalid-feedback d-block js-archive-move-destination-error"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-falcon-primary js-archive-move-confirm" type="button">
                    <span class="fas fa-folder-open me-1"></span>{{ __('archive.move') }}
                </button>
                <button class="btn btn-falcon-default" type="button" data-bs-dismiss="modal">{{ __('archive.cancel') }}</button>
            </div>
        </div>
    </div>
</div>
