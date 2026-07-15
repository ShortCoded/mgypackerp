<div class="modal fade" id="my-board-table-status-modal" tabindex="-1" aria-labelledby="my-board-table-status-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content js-my-board-table-status-form" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="my-board-table-status-modal-title">{{ __('user_tasks.actions.change_status') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="status_url" value="">
                <input type="hidden" name="record_type" value="">

                <div class="alert alert-danger d-none js-my-board-table-status-alert" role="alert"></div>

                <div class="mb-3">
                    <label class="form-label mb-1">{{ __('user_tasks.attributes.current_status') }}</label>
                    <div class="form-control-plaintext fw-semibold js-my-board-table-current-status"></div>
                </div>

                <div class="mb-0">
                    <x-forms.label for="my-board-table-status-new" :label="__('user_tasks.attributes.new_status')" required />
                    <select id="my-board-table-status-new" name="status" class="form-select js-my-board-table-status-select" required>
                        @foreach (\Modules\Core\Models\UserTask::Statuses as $status)
                            <option value="{{ $status }}">{{ __("user_tasks.statuses.{$status}") }}</option>
                        @endforeach
                    </select>
                    <div class="invalid-feedback" data-error-for="status"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-falcon-default btn-sm" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                <button type="submit" class="btn btn-primary btn-sm js-my-board-table-status-save">
                    <span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}
                </button>
            </div>
        </form>
    </div>
</div>
