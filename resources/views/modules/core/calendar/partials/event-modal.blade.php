@php
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
@endphp

<div class="modal fade erp-calendar-modal" id="calendarEventModal" tabindex="-1" aria-labelledby="calendarEventModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form id="calendarEventForm" autocomplete="off" novalidate>
                <div class="modal-header px-x1 bg-body-tertiary">
                    <div>
                        <h5 class="modal-title mb-1 js-calendar-modal-title" id="calendarEventModalLabel">{{ __('calendar.actions.add') }}</h5>
                        <p class="mb-0 fs-10 text-600">{{ __('calendar.help.private_calendar') }}</p>
                    </div>
                    <button class="btn-close me-n1" type="button" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                </div>
                <div class="modal-body p-x1">
                    <div class="alert alert-danger d-none js-calendar-errors" role="alert"></div>

                    <div class="calendar-event-section">
                        <h6 class="calendar-event-section-title">{{ __('calendar.sections.basic_information') }}</h6>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label" for="calendar-event-title">
                                    {{ __('calendar.fields.title') }} <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <input class="form-control" id="calendar-event-title" type="text" name="title" maxlength="255" required aria-required="true">
                                <div class="invalid-feedback" data-error-for="title"></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="calendar-event-status">{{ __('calendar.fields.status') }}</label>
                                <select class="form-select" id="calendar-event-status" name="status">
                                    @foreach (\Modules\Core\Models\CalendarEvent::Statuses as $status)
                                        <option value="{{ $status }}">{{ __("calendar.statuses.{$status}") }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback" data-error-for="status"></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="calendar-event-color">{{ __('calendar.fields.color') }}</label>
                                <select class="form-select" id="calendar-event-color" name="color">
                                    @foreach (\Modules\Core\Models\CalendarEvent::Colors as $color)
                                        <option value="{{ $color }}">{{ __("calendar.colors.{$color}") }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback" data-error-for="color"></div>
                            </div>
                        </div>
                    </div>

                    <div class="calendar-event-section">
                        <h6 class="calendar-event-section-title">{{ __('calendar.sections.schedule') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="calendar-event-starts-at">
                                    {{ __('calendar.fields.starts_at') }} <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <input class="form-control js-date-picker js-calendar-date-time" id="calendar-event-starts-at" type="text" name="starts_at" data-enable-time="true" data-date-format="{{ $dateFormatService->jsDateTimeFormat() }}" data-locale="{{ app()->getLocale() }}" data-minute-increment="5" placeholder="{{ $dateFormatService->dateTimeFormat() }}" autocomplete="off" required aria-required="true" dir="ltr">
                                <div class="invalid-feedback" data-error-for="starts_at"></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="calendar-event-ends-at">{{ __('calendar.fields.ends_at') }}</label>
                                <input class="form-control js-date-picker js-calendar-date-time" id="calendar-event-ends-at" type="text" name="ends_at" data-enable-time="true" data-date-format="{{ $dateFormatService->jsDateTimeFormat() }}" data-locale="{{ app()->getLocale() }}" data-minute-increment="5" placeholder="{{ $dateFormatService->dateTimeFormat() }}" autocomplete="off" dir="ltr">
                                <div class="invalid-feedback" data-error-for="ends_at"></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="calendar-event-reminder-at">{{ __('calendar.fields.reminder') }}</label>
                                <input class="form-control js-date-picker js-calendar-date-time" id="calendar-event-reminder-at" type="text" name="reminder_at" data-enable-time="true" data-date-format="{{ $dateFormatService->jsDateTimeFormat() }}" data-locale="{{ app()->getLocale() }}" data-minute-increment="5" placeholder="{{ $dateFormatService->dateTimeFormat() }}" autocomplete="off" dir="ltr">
                                <div class="invalid-feedback" data-error-for="reminder_at"></div>
                            </div>

                            <div class="col-md-6 d-flex align-items-end">
                                <div>
                                    <div class="form-check form-switch mb-1">
                                        <input class="form-check-input" id="calendar-event-all-day" type="checkbox" name="all_day" value="1">
                                        <label class="form-check-label" for="calendar-event-all-day">{{ __('calendar.fields.all_day') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('calendar.help.all_day') }}</div>
                                    <div class="invalid-feedback d-block" data-field-feedback="all_day" data-error-for="all_day"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="calendar-event-section mb-0">
                        <h6 class="calendar-event-section-title">{{ __('calendar.sections.details') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="calendar-event-location">{{ __('calendar.fields.location') }}</label>
                                <input class="form-control" id="calendar-event-location" type="text" name="location" maxlength="255">
                                <div class="invalid-feedback" data-error-for="location"></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="calendar-event-meeting-url">{{ __('calendar.fields.meeting_url') }}</label>
                                <input class="form-control" id="calendar-event-meeting-url" type="url" name="meeting_url" maxlength="2048" dir="ltr">
                                <div class="invalid-feedback" data-error-for="meeting_url"></div>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="calendar-event-description">{{ __('calendar.fields.description') }}</label>
                                <textarea class="form-control" id="calendar-event-description" rows="4" name="description"></textarea>
                                <div class="invalid-feedback" data-error-for="description"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-body-tertiary border-top-0 px-x1">
                    <button class="btn btn-falcon-danger me-auto js-calendar-delete d-none" type="button" data-shortcut-action="calendar.delete" title="{{ __('calendar.shortcuts.delete') }}" data-bs-title="{{ __('calendar.shortcuts.delete') }}">
                        <span class="fas fa-trash-alt me-1"></span>{{ __('calendar.actions.delete') }}
                    </button>
                    <button class="btn btn-falcon-default" type="button" data-bs-dismiss="modal" data-shortcut-action="calendar.cancel" title="{{ __('calendar.shortcuts.cancel') }}" data-bs-title="{{ __('calendar.shortcuts.cancel') }}">{{ __('common.actions.cancel') }}</button>
                    <button class="btn btn-primary js-calendar-save" type="submit" data-shortcut-action="calendar.save" title="{{ __('calendar.shortcuts.save') }}" data-bs-title="{{ __('calendar.shortcuts.save') }}">{{ __('calendar.actions.save') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
