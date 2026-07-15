(() => {
    const config = window.AppCalendar || {};
    const calendarElement = document.getElementById('erpCalendar');
    const formModalElement = document.getElementById('calendarEventModal');
    const detailsModalElement = document.getElementById('calendarEventDetailsModal');
    const form = document.getElementById('calendarEventForm');

    if (!calendarElement || !formModalElement || !detailsModalElement || !form || calendarElement.dataset.initialized === 'true') {
        return;
    }

    calendarElement.dataset.initialized = 'true';

    const formModal = new window.bootstrap.Modal(formModalElement);
    const detailsModal = new window.bootstrap.Modal(detailsModalElement);
    const detailsContent = detailsModalElement.querySelector('.js-calendar-details-content');
    const titleElement = formModalElement.querySelector('.js-calendar-modal-title');
    const deleteButton = formModalElement.querySelector('.js-calendar-delete');
    const saveButton = formModalElement.querySelector('.js-calendar-save');
    const errorBox = formModalElement.querySelector('.js-calendar-errors');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    let calendar = null;
    let currentEventId = null;
    let currentDetailsEventId = null;
    let isSubmitting = false;

    const labels = config.labels || {};
    const messages = config.messages || {};
    const fields = config.fields || {};
    const permissions = config.can || {};
    const urls = config.urls || {};
    const shortcuts = config.shortcuts || {};

    const field = (name) => form.querySelector(`[name="${name}"]`);
    const eventUrl = (name, id) => (urls[name] || '').replace('__EVENT__', encodeURIComponent(id));
    const isEditable = () => Boolean(permissions.edit);
    const isFormModalOpen = () => formModalElement.classList.contains('show');
    const isDetailsModalOpen = () => detailsModalElement.classList.contains('show');

    const pad = (number) => String(number).padStart(2, '0');

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const escapeAttribute = (value) => escapeHtml(value).replace(/`/g, '&#096;');

    const toDate = (value) => {
        if (!value) {
            return null;
        }

        const date = value instanceof Date ? value : new Date(value);

        if (Number.isNaN(date.getTime())) {
            return null;
        }

        return date;
    };

    const toEventDateString = (date) => {
        if (!date) {
            return null;
        }

        return typeof date.toISOString === 'function' ? date.toISOString() : String(date);
    };

    const fallbackDateTimeValue = (value) => {
        const date = toDate(value);

        if (!date) {
            return '';
        }

        return [
            date.getFullYear(),
            pad(date.getMonth() + 1),
            pad(date.getDate()),
        ].join('-') + ` ${pad(date.getHours())}:${pad(date.getMinutes())}:00`;
    };

    const setDateTimeField = (name, value) => {
        const input = field(name);
        const date = toDate(value);

        if (!input) {
            return;
        }

        if (input._flatpickr) {
            if (date) {
                input._flatpickr.setDate(date, false);
            } else {
                input._flatpickr.clear();
            }

            return;
        }

        input.value = fallbackDateTimeValue(date);
    };

    const dateFromClick = (info) => {
        if (info.date instanceof Date) {
            return info.date;
        }

        return new Date(`${info.dateStr}T09:00:00`);
    };

    const clearValidation = () => {
        form.querySelectorAll('.is-invalid').forEach((element) => element.classList.remove('is-invalid'));
        form.querySelectorAll('[aria-invalid="true"]').forEach((element) => element.removeAttribute('aria-invalid'));
        form.querySelectorAll('.invalid-feedback').forEach((element) => {
            element.textContent = '';
        });

        if (errorBox) {
            errorBox.classList.add('d-none');
            errorBox.textContent = '';
        }
    };

    const setFieldError = (name, message) => {
        const input = field(name);
        const feedback = form.querySelector(`[data-error-for="${name}"]`)
            || input?.closest('.col-12, .col-md-6')?.querySelector('.invalid-feedback')
            || form.querySelector(`[data-field-feedback="${name}"]`);

        if (input) {
            input.classList.add('is-invalid');
            input.setAttribute('aria-invalid', 'true');
        }

        if (feedback) {
            feedback.textContent = message;
        }
    };

    const showError = (message) => {
        if (!errorBox) {
            return;
        }

        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
    };

    const focusField = (name) => {
        const input = field(name);

        if (!input || input.disabled) {
            return;
        }

        input.focus({ preventScroll: false });

        if (input._flatpickr) {
            input._flatpickr.open();
        }
    };

    const validateRequiredFields = () => {
        clearValidation();

        const requiredFields = [
            ['title', messages.requiredTitle],
            ['starts_at', messages.requiredStart],
        ];

        for (let index = 0; index < requiredFields.length; index += 1) {
            const [name, message] = requiredFields[index];
            const input = field(name);

            if (!input || String(input.value || '').trim() !== '') {
                continue;
            }

            setFieldError(name, message || messages.validationFailed);
            showError(messages.validationFailed || message);
            focusField(name);

            return false;
        }

        return true;
    };

    const showToast = (icon, message) => {
        if (!window.AppAlerts || typeof window.AppAlerts.toast !== 'function') {
            return;
        }

        window.AppAlerts.toast(icon, message);
    };

    const requestJson = async (url, method, payload = null) => {
        const options = {
            method,
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
        };

        if (payload !== null) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(payload);
        }

        const response = await fetch(url, options);
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            const error = new Error(data.message || messages.unexpectedError);
            error.status = response.status;
            error.data = data;
            throw error;
        }

        return data;
    };

    const setSubmitting = (submitting) => {
        isSubmitting = submitting;

        if (saveButton) {
            saveButton.disabled = submitting;
        }
    };

    const setFormReadOnly = (readOnly) => {
        form.querySelectorAll('input, select, textarea').forEach((input) => {
            input.disabled = readOnly;
        });

        if (saveButton) {
            saveButton.classList.toggle('d-none', readOnly);
        }
    };

    const resetForm = () => {
        form.reset();
        clearValidation();
        currentEventId = null;
        setSubmitting(false);
        setFormReadOnly(false);

        if (field('status')) {
            field('status').value = 'pending';
        }

        if (field('color')) {
            field('color').value = 'primary';
        }

        if (deleteButton) {
            deleteButton.classList.add('d-none');
        }

        ['starts_at', 'ends_at', 'reminder_at'].forEach((name) => setDateTimeField(name, null));
    };

    const eventProps = (event) => event.extendedProps || {};

    const fillFormFromEvent = (event) => {
        const props = eventProps(event);

        field('title').value = event.title || '';
        field('description').value = props.description || '';
        setDateTimeField('starts_at', event.start);
        setDateTimeField('ends_at', event.end);
        field('all_day').checked = Boolean(event.allDay);
        field('status').value = props.status || 'pending';
        field('color').value = props.color || 'primary';
        field('location').value = props.location || '';
        field('meeting_url').value = props.meeting_url || '';
        setDateTimeField('reminder_at', props.reminder_at);
    };

    const collectPayload = () => ({
        title: field('title').value,
        description: field('description').value,
        starts_at: field('starts_at').value,
        ends_at: field('ends_at').value,
        all_day: field('all_day').checked,
        status: field('status').value,
        color: field('color').value,
        location: field('location').value,
        meeting_url: field('meeting_url').value,
        reminder_at: field('reminder_at').value,
    });

    const openCreateModal = (startsAt = '') => {
        resetForm();
        currentDetailsEventId = null;

        if (titleElement) {
            titleElement.textContent = labels.createTitle || '';
        }

        setDateTimeField('starts_at', startsAt || new Date());
        formModal.show();
    };

    const openEditModal = (event) => {
        const props = eventProps(event);

        resetForm();
        currentEventId = event.id;
        fillFormFromEvent(event);

        if (titleElement) {
            titleElement.textContent = labels.editTitle || '';
        }

        setFormReadOnly(false);

        if (deleteButton) {
            deleteButton.classList.toggle('d-none', !(props.can_delete && permissions.delete));
        }

        detailsModal.hide();
        formModal.show();
    };

    const fullCalendarEventPayload = (event) => ({
        id: event.id,
        title: event.title,
        start: event.startStr || toEventDateString(event.start),
        end: event.endStr || toEventDateString(event.end),
        allDay: event.allDay,
        extendedProps: event.extendedProps || {},
    });

    const detailItem = (icon, label, value, options = {}) => {
        if (!value) {
            return '';
        }

        const content = options.html === true ? value : escapeHtml(value);

        return `
            <div class="calendar-detail-item">
                <span class="calendar-detail-icon"><span class="${icon}"></span></span>
                <div class="flex-1">
                    <h6 class="mb-1">${escapeHtml(label)}</h6>
                    <div class="mb-0 text-700 ${options.className || ''}">${content}</div>
                </div>
            </div>
        `;
    };

    const renderDetails = (event) => {
        const props = eventProps(event);
        const canEditEvent = Boolean(props.can_edit) && isEditable();
        const canDeleteEvent = Boolean(props.can_delete) && Boolean(permissions.delete);
        const statusClass = props.status_class || props.color || 'primary';
        const meetingUrl = props.meeting_url
            ? `<a href="${escapeAttribute(props.meeting_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(props.meeting_url)}</a>`
            : '';

        currentDetailsEventId = event.id;

        detailsContent.innerHTML = `
            <div class="modal-header bg-body-tertiary ps-card pe-5 border-bottom-0">
                <div>
                    <h5 class="modal-title mb-0" id="calendarEventDetailsModalLabel">${escapeHtml(event.title || labels.viewTitle || '')}</h5>
                    ${props.status_label ? `<p class="mb-0 fs-10 mt-1"><span class="badge badge-subtle-${escapeAttribute(statusClass)}">${escapeHtml(props.status_label)}</span></p>` : ''}
                </div>
                <button type="button" class="btn-close position-absolute end-0 top-0 mt-3 me-3" data-bs-dismiss="modal" data-shortcut-action="calendar.cancel" title="${escapeAttribute(shortcuts.cancel || '')}" data-bs-title="${escapeAttribute(shortcuts.cancel || '')}" aria-label="${escapeAttribute(labels.close || '')}"></button>
            </div>
            <div class="modal-body px-card pb-card pt-1 fs-10">
                ${detailItem('fas fa-calendar-check', `${fields.startsAt || ''}${event.end ? ` / ${fields.endsAt || ''}` : ''}`, props.formatted_range || [
                    props.formatted_start || '',
                    event.end ? (props.formatted_end || '') : '',
                ].filter(Boolean).join(' - '))}
                ${detailItem('fas fa-clock', fields.allDay || '', event.allDay ? (fields.allDay || '') : '')}
                ${detailItem('fas fa-map-marker-alt', fields.location || '', props.location)}
                ${detailItem('fas fa-video', fields.meetingUrl || '', meetingUrl, { html: true })}
                ${detailItem('fas fa-align-left', fields.description || '', props.description, { className: 'calendar-detail-description' })}
                ${detailItem('fas fa-bell', fields.reminderAt || '', props.formatted_reminder_at || '')}
                ${detailItem('fas fa-calendar-plus', fields.createdAt || '', props.formatted_created_at || '')}
                ${detailItem('fas fa-pen', fields.updatedAt || '', props.formatted_updated_at || '')}
            </div>
            <div class="modal-footer d-flex justify-content-end bg-body-tertiary px-card border-top-0">
                ${canDeleteEvent ? `<button type="button" class="btn btn-falcon-danger btn-sm me-auto js-calendar-details-delete" data-shortcut-action="calendar.delete" title="${escapeAttribute(shortcuts.delete || '')}" data-bs-title="${escapeAttribute(shortcuts.delete || '')}"><span class="fas fa-trash-alt me-1"></span>${escapeHtml(labels.delete || '')}</button>` : ''}
                <button type="button" class="btn btn-falcon-default btn-sm" data-bs-dismiss="modal" data-shortcut-action="calendar.cancel" title="${escapeAttribute(shortcuts.cancel || '')}" data-bs-title="${escapeAttribute(shortcuts.cancel || '')}">${escapeHtml(labels.close || '')}</button>
                ${canEditEvent ? `<button type="button" class="btn btn-falcon-primary btn-sm js-calendar-details-edit" data-shortcut-action="calendar.edit" title="${escapeAttribute(shortcuts.edit || '')}" data-bs-title="${escapeAttribute(shortcuts.edit || '')}"><span class="fas fa-pencil-alt me-1"></span>${escapeHtml(labels.edit || '')}</button>` : ''}
            </div>
        `;

        detailsContent.querySelector('.js-calendar-details-edit')?.addEventListener('click', () => openEditModal(event));
        detailsContent.querySelector('.js-calendar-details-delete')?.addEventListener('click', () => {
            currentEventId = event.id;
            deleteCurrentEvent();
        });
    };

    const renderDetailsLoading = () => {
        detailsContent.innerHTML = `
            <div class="modal-header px-card bg-body-tertiary border-bottom-0">
                <h5 class="modal-title mb-0">${escapeHtml(labels.viewTitle || '')}</h5>
                <button class="btn-close me-n1" type="button" data-bs-dismiss="modal" data-shortcut-action="calendar.cancel" title="${escapeAttribute(shortcuts.cancel || '')}" data-bs-title="${escapeAttribute(shortcuts.cancel || '')}" aria-label="${escapeAttribute(labels.close || '')}"></button>
            </div>
            <div class="modal-body px-card pb-card">
                <div class="text-center py-4 text-600">${escapeHtml(messages.loading || 'Loading...')}</div>
            </div>
        `;
    };

    const openDetailsModal = async (calendarEvent) => {
        const fallbackEvent = fullCalendarEventPayload(calendarEvent);

        renderDetailsLoading();
        detailsModal.show();

        try {
            const response = await requestJson(eventUrl('show', calendarEvent.id), 'GET');
            renderDetails(response.data?.event || fallbackEvent);
        } catch (error) {
            detailsModal.hide();
            showToast('error', error.status === 403 ? messages.notAllowedManage : (error.message || messages.couldNotLoad));
        }
    };

    const handleValidationError = (error) => {
        clearValidation();

        if (error.status !== 422 || !error.data?.errors) {
            showError(error.status === 403 ? messages.notAllowedManage : (error.message || messages.unexpectedError));
            return;
        }

        showError(messages.validationFailed || error.message);

        let firstInvalidField = null;

        Object.entries(error.data.errors).forEach(([name, errors]) => {
            if (firstInvalidField === null) {
                firstInvalidField = name;
            }

            setFieldError(name, Array.isArray(errors) ? errors[0] : String(errors));
        });

        if (firstInvalidField) {
            focusField(firstInvalidField);
        }
    };

    const submitForm = async (event) => {
        event.preventDefault();

        if (isSubmitting || !validateRequiredFields()) {
            return;
        }

        const url = currentEventId ? eventUrl('update', currentEventId) : urls.store;
        const method = currentEventId ? 'PUT' : 'POST';

        try {
            setSubmitting(true);
            const response = await requestJson(url, method, collectPayload());
            formModal.hide();
            calendar?.refetchEvents();
            showToast('success', response.message || (currentEventId ? messages.updated : messages.created));
        } catch (error) {
            handleValidationError(error);
            showToast('error', error.message || (currentEventId ? messages.couldNotUpdate : messages.couldNotSave));
        } finally {
            setSubmitting(false);
        }
    };

    const eventCanMove = (event) => Boolean(event?.extendedProps?.can_edit) && isEditable();

    const moveEvent = async (info) => {
        if (!eventCanMove(info.event)) {
            info.revert();
            showToast('error', messages.notAllowedManage || messages.forbiddenEdit);
            return;
        }

        try {
            const response = await requestJson(eventUrl('move', info.event.id), 'PATCH', {
                starts_at: info.event.startStr || toEventDateString(info.event.start),
                ends_at: info.event.endStr || toEventDateString(info.event.end),
                all_day: info.event.allDay,
            });

            calendar?.refetchEvents();
            showToast('success', response.message || messages.moved);
        } catch (error) {
            info.revert();
            showToast('error', error.status === 403 ? messages.notAllowedManage : (error.message || messages.unexpectedError));
        }
    };

    const deleteCurrentEvent = async () => {
        const eventId = currentEventId || currentDetailsEventId;

        if (!eventId || !permissions.delete) {
            return;
        }

        let confirmed = true;

        if (window.Swal) {
            const result = await window.Swal.fire({
                title: messages.deleteConfirm,
                icon: 'warning',
                focusCancel: true,
                showCloseButton: true,
                allowEscapeKey: true,
                showCancelButton: true,
                confirmButtonText: labels.delete,
                cancelButtonText: labels.cancel,
                reverseButtons: config.direction === 'rtl',
            });

            confirmed = result.isConfirmed;
        } else {
            confirmed = false;
        }

        if (!confirmed) {
            return;
        }

        try {
            const response = await requestJson(eventUrl('destroy', eventId), 'DELETE');
            formModal.hide();
            detailsModal.hide();
            currentEventId = null;
            currentDetailsEventId = null;
            calendar?.refetchEvents();
            showToast('success', response.message || messages.deleted);
        } catch (error) {
            showError(error.status === 403 ? messages.notAllowedManage : (error.message || messages.couldNotDelete));
            showToast('error', error.message || messages.couldNotDelete);
        }
    };

    calendar = new window.FullCalendar.Calendar(calendarElement, {
        headerToolbar: false,
        initialView: 'dayGridMonth',
        initialDate: config.initialDate || undefined,
        direction: config.direction || 'ltr',
        locale: config.locale || 'en',
        timeZone: config.timezone || 'local',
        firstDay: Number.isInteger(config.firstDay) ? config.firstDay : 0,
        height: 800,
        dayMaxEvents: 2,
        stickyHeaderDates: false,
        selectable: Boolean(permissions.create),
        editable: isEditable(),
        eventStartEditable: isEditable(),
        eventDurationEditable: isEditable(),
        eventResizableFromStart: isEditable(),
        eventAllow(dropInfo, draggedEvent) {
            return eventCanMove(draggedEvent);
        },
        eventTimeFormat: {
            hour: 'numeric',
            minute: '2-digit',
            omitZeroMinute: true,
            meridiem: true,
        },
        buttonText: {
            today: labels.today || 'Today',
            month: labels.month || 'Month',
            week: labels.week || 'Week',
            day: labels.day || 'Day',
            list: labels.list || 'List',
        },
        events: {
            url: urls.events,
            method: 'GET',
            failure(error) {
                console.error('Calendar event load failed', error);
                showToast('error', messages.couldNotLoad || messages.unexpectedError);
            },
        },
        dateClick(info) {
            if (!permissions.create) {
                return;
            }

            openCreateModal(dateFromClick(info));
            field('all_day').checked = Boolean(info.allDay);
        },
        eventClick(info) {
            info.jsEvent.preventDefault();
            openDetailsModal(info.event);
        },
        eventDidMount(info) {
            info.el.setAttribute('role', 'button');
            info.el.setAttribute('tabindex', '0');
        },
        eventDrop: moveEvent,
        eventResize: moveEvent,
    });

    calendar.render();

    window.setTimeout(() => calendar.updateSize(), 0);

    const updateTitle = () => {
        const title = document.querySelector('.calendar-title');

        if (title) {
            title.textContent = calendar.currentData.viewTitle;
        }
    };

    updateTitle();

    document.querySelectorAll('[data-calendar-action]').forEach((button) => {
        button.addEventListener('click', (event) => {
            const action = event.currentTarget.dataset.calendarAction;

            if (action === 'prev') {
                calendar.prev();
            } else if (action === 'next') {
                calendar.next();
            } else {
                calendar.today();
            }

            updateTitle();
        });
    });

    document.querySelectorAll('[data-calendar-view]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();

            const active = event.currentTarget.parentElement?.querySelector('.active');

            if (active) {
                active.classList.remove('active');
            }

            event.currentTarget.classList.add('active');
            calendar.changeView(event.currentTarget.dataset.calendarView);
            updateTitle();

            const viewTitle = document.querySelector('[data-calendar-view-title]');

            if (viewTitle) {
                viewTitle.textContent = event.currentTarget.textContent.trim();
            }
        });
    });

    document.querySelectorAll('.js-calendar-add').forEach((button) => {
        button.addEventListener('click', () => openCreateModal());
    });

    const isTypingTarget = (target) => {
        if (!target) {
            return false;
        }

        if (target.isContentEditable || (typeof target.closest === 'function' && target.closest('[contenteditable="true"], .select2-search__field'))) {
            return true;
        }

        const tagName = (target.tagName || '').toLowerCase();

        return tagName === 'textarea' || tagName === 'select' || tagName === 'input';
    };

    const keyMatches = (event, codes, keyCodes) => {
        const code = event.code || '';
        const keyCode = Number(event.keyCode || event.which || 0);

        return (code !== '' && code !== 'Unidentified' && codes.includes(code)) || keyCodes.includes(keyCode);
    };

    document.addEventListener('keydown', (event) => {
        const isDeleteShortcut = !event.ctrlKey
            && !event.metaKey
            && !event.altKey
            && !event.shiftKey
            && keyMatches(event, ['Delete'], [46]);
        const isEscapeShortcut = !event.ctrlKey
            && !event.metaKey
            && !event.altKey
            && !event.shiftKey
            && keyMatches(event, ['Escape'], [27]);
        const isTodayShortcut = window.AppShortcuts && typeof window.AppShortcuts.isAltShortcut === 'function'
            ? window.AppShortcuts.isAltShortcut(event, ['KeyT'], [84])
            : event.altKey && !event.metaKey && !event.shiftKey && keyMatches(event, ['KeyT'], [84]);

        if (isTypingTarget(event.target)) {
            return;
        }

        if (isEscapeShortcut && isDetailsModalOpen()) {
            event.preventDefault();
            event.stopPropagation();
            detailsModal.hide();
            return;
        }

        if (isDeleteShortcut && (isFormModalOpen() || isDetailsModalOpen())) {
            const visibleDeleteButton = isFormModalOpen()
                ? deleteButton && !deleteButton.classList.contains('d-none')
                : detailsContent.querySelector('.js-calendar-details-delete');

            if (visibleDeleteButton) {
                event.preventDefault();
                event.stopPropagation();
                visibleDeleteButton.click();
            }

            return;
        }

        if (isTodayShortcut) {
            event.preventDefault();
            event.stopPropagation();
            calendar.today();
            updateTitle();
        }
    }, true);

    form.addEventListener('submit', submitForm);
    deleteButton?.addEventListener('click', deleteCurrentEvent);

    formModalElement.addEventListener('shown.bs.modal', () => {
        window.AppDatePicker?.init?.(formModalElement);
        field('title')?.focus();
    });

    formModalElement.addEventListener('hidden.bs.modal', () => {
        clearValidation();
    });

    detailsModalElement.addEventListener('hidden.bs.modal', () => {
        currentDetailsEventId = null;
    });

    window.AppDatePicker?.init?.(formModalElement);
})();
