<div class="modal fade erp-calendar-details-modal" id="calendarEventDetailsModal" tabindex="-1" aria-labelledby="calendarEventDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow js-calendar-details-content">
            <div class="modal-header px-card bg-body-tertiary border-bottom-0">
                <div>
                    <h5 class="modal-title mb-0" id="calendarEventDetailsModalLabel">{{ __('calendar.actions.view_event') }}</h5>
                    <p class="mb-0 fs-10 text-600">{{ __('calendar.help.private_calendar') }}</p>
                </div>
                <button class="btn-close me-n1" type="button" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
            </div>
            <div class="modal-body px-card pb-card pt-1">
                <div class="text-center py-4 text-600">{{ __('common.messages.loading') }}</div>
            </div>
        </div>
    </div>
</div>
