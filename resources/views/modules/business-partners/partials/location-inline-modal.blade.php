<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content js-business-location-inline-form" method="POST" novalidate>
            @csrf
            <input type="hidden" name="target_select" value="">
            <input type="hidden" name="parent_field" value="">
            <input type="hidden" name="parent_value" value="">
            <div class="modal-header">
                <h5 class="modal-title js-location-inline-title">{{ __('business_partners.actions.add_location') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
            </div>
            <div class="modal-body">
                <div class="alert d-none js-form-alert"><div class="js-form-alert-message"></div></div>
                <div class="mb-3">
                    <x-forms.label for="{{ $modalId }}_name" :label="__('business_partners.attributes.location_name')" required />
                    <input class="form-control js-location-inline-name" id="{{ $modalId }}_name" name="name" type="text" required>
                    <div class="invalid-feedback" data-error-for="name"></div>
                </div>
                <div>
                    <label class="form-label" for="{{ $modalId }}_notes">{{ __('business_partners.attributes.notes') }}</label>
                    <textarea class="form-control" id="{{ $modalId }}_notes" name="notes" rows="3"></textarea>
                    <div class="invalid-feedback" data-error-for="notes"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-falcon-default" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                <button type="submit" class="btn btn-primary"><span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}</button>
            </div>
        </form>
    </div>
</div>
