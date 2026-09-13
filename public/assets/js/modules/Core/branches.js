(function ($, window) {
    'use strict';

    const messages = window.branchesMessages || window.coreBranchesMessages || {};
    const csrfToken = $('meta[name="csrf-token"]').attr('content');

    function headers() {
        return {
            'X-CSRF-TOKEN': csrfToken,
            Accept: 'application/json'
        };
    }

    function showToast(icon, title) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
        }
    }

    function confirmDialog(options) {
        if (!window.Swal) {
            return $.Deferred().resolve({ isConfirmed: false }).promise();
        }

        return Swal.fire({
            icon: 'warning',
            title: options.title,
            text: options.text,
            showCloseButton: true,
            showCancelButton: true,
            focusCancel: true,
            allowEscapeKey: true,
            confirmButtonText: options.confirmButtonText,
            cancelButtonText: options.cancelButtonText || messages.no || '',
            confirmButtonColor: options.confirmButtonColor || '#d33',
            cancelButtonColor: '#748194'
        });
    }

    function currentFormData($form) {
        return {
            doc_number: String($form.find('[name="doc_number"]').val() || '').trim(),
            company_doc_num: String($form.find('[name="company_doc_num"]').val() || '').trim(),
            name: String($form.find('[name="name"]').val() || '').trim(),
            type: String($form.find('[name="type"]').val() || '').trim(),
            station_halls: stationHallNames($form),
            branch_stores: branchStoreNames($form),
            address: String($form.find('[name="address"]').val() || '').trim(),
            attendance_latitude: String($form.find('[name="attendance_latitude"]').val() || '').trim(),
            attendance_longitude: String($form.find('[name="attendance_longitude"]').val() || '').trim(),
            attendance_radius_meters: String($form.find('[name="attendance_radius_meters"]').val() || '').trim(),
            attendance_max_accuracy_meters: String($form.find('[name="attendance_max_accuracy_meters"]').val() || '').trim(),
            attendance_location_policy: String($form.find('[name="attendance_location_policy"]').val() || '').trim(),
            camera_url: String($form.find('[name="camera_url"]').val() || '').trim(),
            phone: String($form.find('[name="phone"]').val() || '').trim(),
            mobile: String($form.find('[name="mobile"]').val() || '').trim(),
            email: String($form.find('[name="email"]').val() || '').trim(),
            hotline: String($form.find('[name="hotline"]').val() || '').trim(),
            contact_person: String($form.find('[name="contact_person"]').val() || '').trim(),
            notes: String($form.find('[name="notes"]').val() || '').trim(),
            status: String($form.find('[name="status"]').val() || '').trim()
        };
    }

    function originalFormData($form) {
        const original = $form.data('original') || {};

        return {
            doc_number: original.doc_number === null || original.doc_number === undefined ? '' : String(original.doc_number).trim(),
            company_doc_num: String(original.company_doc_num || '').trim(),
            name: String(original.name || '').trim(),
            type: String(original.type || '').trim(),
            station_halls: Array.isArray(original.station_halls) ? original.station_halls.map(function (hall) {
                if (typeof hall === 'string') {
                    return { key: '', name: hall.trim() };
                }

                return {
                    key: String((hall && hall.key) || '').trim(),
                    name: String((hall && hall.name) || '').trim()
                };
            }).filter(function (hall) { return hall.name !== ''; }) : [],
            branch_stores: Array.isArray(original.branch_stores) ? original.branch_stores.map(function (store) {
                if (typeof store === 'string') {
                    return { key: '', name: store.trim(), classification: '' };
                }

                return {
                    key: String((store && store.key) || '').trim(),
                    name: String((store && store.name) || '').trim(),
                    classification: String((store && store.classification) || '').trim()
                };
            }).filter(function (store) { return store.name !== ''; }) : [],
            address: String(original.address || '').trim(),
            attendance_latitude: String(original.attendance_latitude || '').trim(),
            attendance_longitude: String(original.attendance_longitude || '').trim(),
            attendance_radius_meters: String(original.attendance_radius_meters || '200').trim(),
            attendance_max_accuracy_meters: String(original.attendance_max_accuracy_meters || '100').trim(),
            attendance_location_policy: String(original.attendance_location_policy || 'warn').trim(),
            camera_url: String(original.camera_url || '').trim(),
            phone: String(original.phone || '').trim(),
            mobile: String(original.mobile || '').trim(),
            email: String(original.email || '').trim(),
            hotline: String(original.hotline || '').trim(),
            contact_person: String(original.contact_person || '').trim(),
            notes: String(original.notes || '').trim(),
            status: String(original.status || '').trim()
        };
    }

    function hasChanges($form) {
        if ($form.data('mode') !== 'edit') {
            return true;
        }

        if (!$form.data('original')) {
            return true;
        }

        const original = originalFormData($form);
        const current = currentFormData($form);

        return Object.keys(current).some(function (field) {
            if ($.isArray(current[field]) || Array.isArray(current[field])) {
                return JSON.stringify(original[field] || []) !== JSON.stringify(current[field] || []);
            }

            return original[field] !== current[field];
        });
    }

    function alertElement($form) {
        let $alert = $form.find('.js-branch-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger alert-dismissible fade show d-none js-branch-alert" role="alert"><span class="js-branch-alert-message"></span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' + (messages.close || '') + '"></button></div>');
            $form.find('.js-branch-form-body, .card-body').first().prepend($alert);
        }

        return $alert;
    }

    function validationMessages(errors) {
        const result = [];

        Object.keys(errors || {}).forEach(function (field) {
            const values = $.isArray(errors[field]) ? errors[field] : [errors[field]];
            values.forEach(function (message) {
                if (message) {
                    result.push(message);
                }
            });
        });

        return result;
    }

    function showValidationErrors($form, errors) {
        const $alert = alertElement($form);
        const $list = $('<ul class="mb-0 ps-3"></ul>');

        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');

        validationMessages(errors).forEach(function (message) {
            $list.append($('<li></li>').text(message));
        });

        $alert
            .removeClass('d-none alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-branch-alert-message')
            .empty()
            .append($list);

        Object.keys(errors || {}).forEach(function (field) {
            const normalizedField = field.replace(/\.\d+$/, '');
            const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
            const nestedName = fieldNameFromError(field);
            const $input = $form.find('[name="' + normalizedField + '"], [name="' + normalizedField + '[]"], [name="' + nestedName + '"]');

            $input.addClass('is-invalid');
            $form.find('[data-error-for="' + field + '"], [data-error-for="' + normalizedField + '"]').text(message || '');
        });
    }

    function showFormNotice($form, message, type) {
        alertElement($form)
            .removeClass('d-none alert-danger alert-warning alert-success alert-info')
            .addClass('alert-' + (type || 'danger'))
            .find('.js-branch-alert-message')
            .text(message || messages.unexpectedError);
    }

    function clearFormErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        alertElement($form)
            .addClass('d-none')
            .removeClass('alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-branch-alert-message')
            .empty();
    }

    function setLoading($button, loading) {
        $button.prop('disabled', loading);
        $button.css('cursor', loading ? 'wait' : '');
        $('body').css('cursor', loading ? 'wait' : '');
    }

    function updateOriginalFormData($form) {
        $form.data('original', currentFormData($form));
    }

    function initSelect2(root) {
        if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
            window.AppSelect2Ajax.init(root || document);
        }
    }

    function branchType($form) {
        return String($form.find('[name="type"]').val() || $form.data('branch-type') || '').trim();
    }

    function fieldNameFromError(field) {
        const parts = String(field || '').split('.');

        if (parts.length < 2) {
            return field;
        }

        return parts.shift() + '[' + parts.join('][') + ']';
    }

    function errorFieldFromName(name) {
        return String(name || '')
            .replace(/\]/g, '')
            .replace(/\[/g, '.')
            .replace(/\.$/, '');
    }

    function stationHallNames($form) {
        if (branchType($form) !== 'factory') {
            return [];
        }

        return $form.find('.js-station-hall-row').map(function () {
            const $row = $(this);
            const name = String($row.find('.js-station-hall-input').val() || '').trim();

            return {
                key: String($row.find('[name$="[key]"]').val() || '').trim(),
                name: name
            };
        }).get().filter(function (hall) {
            return hall.name !== '';
        });
    }

    function nextStationHallIndex($form) {
        const indexes = $form.find('.js-station-hall-row').map(function () {
            return parseInt($(this).attr('data-station-hall-index'), 10);
        }).get().filter(function (index) {
            return !isNaN(index);
        });

        return indexes.length ? Math.max.apply(Math, indexes) + 1 : 0;
    }

    function stationHallRow(index, data) {
        const placeholder = messages.stationHallPlaceholder || '';
        const removeLabel = messages.stationHallRemove || '';
        const hall = typeof data === 'string' ? { key: '', name: data } : (data || {});

        return $('<div class="input-group input-group-sm mb-2 js-station-hall-row"></div>')
            .attr('data-station-hall-index', index)
            .append($('<input type="hidden">').attr('name', 'station_halls[' + index + '][key]').val(hall.key || ''))
            .append($('<input class="form-control js-station-hall-input" type="text">').attr({
                name: 'station_halls[' + index + '][name]',
                placeholder: placeholder
            }).val(hall.name || ''))
            .append($('<button class="btn btn-falcon-default js-remove-station-hall" type="button"></button>').attr('aria-label', removeLabel).append('<span class="fas fa-times"></span>'))
            .append($('<div class="invalid-feedback"></div>').attr('data-error-for', 'station_halls.' + index + '.name'));
    }

    function branchStoreNames($form) {
        return $form.find('.js-branch-store-row').map(function () {
            const $row = $(this);
            const name = String($row.find('.js-branch-store-input').val() || '').trim();

            return {
                key: String($row.find('[name$="[key]"]').val() || '').trim(),
                name: name,
                classification: String($row.find('.js-branch-store-classification').val() || '').trim()
            };
        }).get().filter(function (store) {
            return store.name !== '';
        });
    }

    function nextBranchStoreIndex($form) {
        const indexes = $form.find('.js-branch-store-row').map(function () {
            return parseInt($(this).attr('data-branch-store-index'), 10);
        }).get().filter(function (index) {
            return !isNaN(index);
        });

        return indexes.length ? Math.max.apply(Math, indexes) + 1 : 0;
    }

    function branchStoreRow(index, data) {
        const placeholder = messages.branchStorePlaceholder || '';
        const removeLabel = messages.branchStoreRemove || '';
        const nameLabel = messages.branchStoreName || '';
        const classificationLabel = messages.branchStoreClassification || '';
        const classificationPlaceholder = messages.branchStoreClassificationPlaceholder || '';
        const classifications = messages.branchStoreClassifications || {};
        const store = typeof data === 'string' ? { key: '', name: data, classification: '' } : (data || {});
        const $classification = $('<select class="form-select form-select-sm js-branch-store-classification"></select>')
            .attr({
                id: 'branch-store-classification-' + index,
                name: 'branch_stores[' + index + '][classification]'
            })
            .append($('<option></option>').val('').text(classificationPlaceholder));

        Object.keys(classifications).forEach(function (classification) {
            $classification.append($('<option></option>').val(classification).text(classifications[classification]));
        });
        $classification.val(store.classification || '');

        return $('<div class="row g-2 align-items-stretch mb-2 js-branch-store-row"></div>')
            .attr('data-branch-store-index', index)
            .append($('<input type="hidden">').attr('name', 'branch_stores[' + index + '][key]').val(store.key || ''))
            .append($('<div class="col-12 col-md-5"></div>')
                .append($('<label class="form-label small"></label>').attr('for', 'branch-store-name-' + index).text(nameLabel))
                .append($('<input class="form-control form-control-sm js-branch-store-input" type="text">').attr({
                    id: 'branch-store-name-' + index,
                    name: 'branch_stores[' + index + '][name]',
                    placeholder: placeholder
                }).val(store.name || ''))
                .append($('<div class="invalid-feedback"></div>').attr('data-error-for', 'branch_stores.' + index + '.name')))
            .append($('<div class="col"></div>')
                .append($('<label class="form-label small"></label>').attr('for', 'branch-store-classification-' + index).text(classificationLabel))
                .append($classification)
                .append($('<div class="invalid-feedback"></div>').attr('data-error-for', 'branch_stores.' + index + '.classification')))
            .append($('<div class="col-auto d-flex align-items-end"></div>')
                .append($('<button class="btn btn-falcon-default btn-sm btn-icon-only px-2 js-remove-branch-store" type="button"></button>').attr({
                    'aria-label': removeLabel,
                    title: removeLabel
                }).append('<span class="fas fa-times"></span>')));
    }

    function ensureBranchStoreRow($form) {
        const $list = $form.find('.js-branch-stores-list');

        if ($list.length > 0 && $list.find('.js-branch-store-row').length === 0) {
            $list.append(branchStoreRow(0, {}));
        }
    }

    function ensureStationHallRow($form) {
        const $list = $form.find('.js-station-halls-list');

        if ($list.length > 0 && $list.find('.js-station-hall-row').length === 0) {
            $list.append(stationHallRow(0, {}));
        }
    }

    function syncStationHallsVisibility($form) {
        const isFactory = branchType($form) === 'factory';
        const $section = $form.find('.js-station-halls-section');
        const $tabItem = $form.find('.js-station-halls-tab-item');

        $section.toggleClass('d-none', !isFactory);
        $tabItem.toggleClass('d-none', !isFactory);

        if (isFactory) {
            ensureStationHallRow($form);
        } else {
            $section.find('.is-invalid').removeClass('is-invalid');
            $form.find('[data-error-for="station_halls"]').text('');
            if ($tabItem.find('.nav-link').hasClass('active')) {
                showBasicTab($form);
            }
        }
    }

    function showBasicTab($form) {
        const element = $form.find('#branch-basic-tab').get(0);

        if (element && window.bootstrap && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(element).show();
        }
    }

    function syncBranchDetailTabs($form) {
        syncStationHallsVisibility($form);
    }

    function isShortcutTypingTarget(target) {
        const element = target || document.activeElement;

        if (!element) {
            return false;
        }

        const $target = $(element);

        return $target.is('input, textarea, select, [contenteditable="true"], [contenteditable=""]')
            || $target.closest('.select2-container, .select2-dropdown, .select2-search, .note-editor').length > 0;
    }

    function isUsableShortcutButton($button) {
        return $button.length > 0
            && $button.is(':visible')
            && !$button.prop('disabled')
            && $button.closest('.d-none, [hidden]').length === 0;
    }

    function visibleBranchForm() {
        return $('.js-branch-form').filter(function () {
            return $(this).is(':visible') && $(this).data('mode') !== 'view';
        }).first();
    }

    function initBranchDetailShortcuts() {
        $(document).off('keydown.branchesDetailShortcuts').on('keydown.branchesDetailShortcuts', function (event) {
            const isAltShortcut = window.AppShortcuts && typeof window.AppShortcuts.isAltPressed === 'function'
                ? window.AppShortcuts.isAltPressed(event) && !event.metaKey && !event.shiftKey
                : event.altKey && !event.metaKey && !event.shiftKey;

            if (!isAltShortcut || isShortcutTypingTarget(event.target)) {
                return;
            }

            const code = event.code || '';
            const keyCode = event.keyCode || event.which;
            const $form = visibleBranchForm();
            let $button = $();

            if ($form.length === 0) {
                return;
            }

            if (code === 'KeyH' || keyCode === 72) {
                $button = $form.find('.js-station-halls-section:not(.d-none) .js-add-station-hall').first();
            }

            if (code === 'KeyS' || keyCode === 83) {
                $button = $form.find('.js-add-branch-store').first();
            }

            if (!isUsableShortcutButton($button)) {
                return;
            }

            event.preventDefault();
            $button.trigger('click');
        });
    }

    function resetBranchCreateForm($form, response) {
        const nextDocNumber = response && response.next_doc_number ? response.next_doc_number : '';

        $form.find('[name="name"], [name="address"], [name="camera_url"], [name="phone"], [name="mobile"], [name="email"], [name="hotline"], [name="contact_person"], [name="notes"]').val('');
        $form.find('[name="doc_number"]').val(nextDocNumber || '');
        $form.find('[name="company_doc_num"]').val(null).trigger('change');
        $form.find('[name="type"]').val('administrative').trigger('change');
        $form.find('[name="status"]').val('active');
        $form.find('[name="attendance_radius_meters"]').val('200');
        $form.find('[name="attendance_max_accuracy_meters"]').val('100');
        $form.find('[name="attendance_location_policy"]').val('warn');
        $form.find('.js-station-halls-list').empty().append(stationHallRow(0, {}));
        $form.find('.js-branch-stores-list').empty().append(branchStoreRow(0, {}));
        $form.find('[name="submit_action"]').val('save');
        $form.find('[name="clone_source_token"]').remove();
        updateOriginalFormData($form);
    }

    function updateUrlsAfterDocNumberChange($form, response) {
        const data = response && response.data ? response.data : {};
        const urls = data.urls || {};

        if (!data.old_doc_num || !data.doc_num || data.old_doc_num === data.doc_num) {
            return;
        }

        if (urls.update) {
            $form.attr('action', urls.update);
        }

        $('a[href*="' + data.old_doc_num + '"]').each(function () {
            const $link = $(this);
            $link.attr('href', String($link.attr('href')).replace(data.old_doc_num, data.doc_num));

            if ($.trim($link.text()) === data.old_doc_num) {
                $link.text(data.doc_num);
            }
        });

        if (window.history && window.location.pathname.indexOf(data.old_doc_num) !== -1) {
            window.history.replaceState({}, '', window.location.pathname.replace(data.old_doc_num, data.doc_num) + window.location.search + window.location.hash);
        }
    }

    function initBranchesTable() {
        const $table = $('#branches-table');

        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) { return options; };
        const protectedColumns = [0, 1, -1];
        const responsiveControlTarget = 1;
        const selectedDocNums = new Set();
        const rowCheckboxSelector = 'tbody tr:not(.child) input.js-record-select, tbody tr:not(.child) input.js-branch-row-checkbox';
        const selectAllSelector = '#select_all_records, #branches-select-all';
        const trashFilterSelector = '#branches_trash_filter';

        function checkboxDocNum(checkbox) {
            return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
        }

        function pageCheckboxes(api) {
            return api && typeof api.rows === 'function'
                ? $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select, input.js-branch-row-checkbox')
                : $table.find(rowCheckboxSelector);
        }

        function trashFilterValue() {
            const value = String($(trashFilterSelector).val() || 'active');

            return ['active', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
        }

        function updateBulkActionsUi() {
            const selectedCount = selectedDocNums.size;
            const $actions = $('#bulk_actions_bar');
            const $applyButton = $('#bulk_action_apply');
            const applyLabel = $applyButton.data('label') || '';

            $actions.toggleClass('d-none', selectedCount === 0).toggleClass('d-flex', selectedCount > 0);
            $('#bulk_selected_count').text(selectedCount);
            $applyButton
                .prop('disabled', selectedCount === 0)
                .find('span:last')
                .text(applyLabel + (selectedCount > 0 ? ' (' + selectedCount + ')' : ''));

            if (selectedCount === 0) {
                $('#bulk_action_select').val('delete');
            }
        }

        function patchResponsiveControlTarget(api) {
            const settings = api && typeof api.settings === 'function' ? api.settings()[0] : null;
            const responsive = settings && settings._responsive ? settings._responsive : null;

            if (!responsive || responsive._erpControlTargetPatched) {
                return;
            }

            responsive.c.details.target = responsiveControlTarget;
            responsive._erpControlTargetPatched = true;
            responsive._controlClass = function () {
                const dt = this.s.dt;

                dt.cells(null, function (index) {
                    return index !== responsiveControlTarget;
                }, { page: 'current' }).nodes().to$().filter('.dtr-control').removeClass('dtr-control').removeAttr('tabindex').removeData('dtr-keyboard');
                dt.cells(null, responsiveControlTarget, { page: 'current' }).nodes().to$().addClass('dtr-control');
                this._tabIndexes();
            };
        }

        function syncResponsiveControlColumn(api) {
            patchResponsiveControlTarget(api);

            const $rows = api && typeof api.rows === 'function' ? $(api.rows({ page: 'current' }).nodes()) : $table.find('tbody tr:not(.child)');
            $table.find('thead th').eq(0).removeClass('dtr-control');
            $table.find('thead th').eq(responsiveControlTarget).addClass('dtr-control');
            $rows.each(function () {
                const $cells = $(this).children('td, th');

                $cells.eq(0).removeClass('dtr-control').removeAttr('tabindex').removeData('dtr-keyboard');
                $cells.eq(responsiveControlTarget).addClass('dtr-control');
            });
        }

        function queueResponsiveControlSync(api) {
            syncResponsiveControlColumn(api);
            window.requestAnimationFrame(function () { syncResponsiveControlColumn(api); });
            window.setTimeout(function () { syncResponsiveControlColumn(api); }, 50);
        }

        function updateSelectAllState(api) {
            const selectableDocNums = pageCheckboxes(api).map(function () {
                return checkboxDocNum(this);
            }).get().filter(function (docNum) {
                return docNum !== '';
            });
            const checkedOnPage = selectableDocNums.filter(function (docNum) {
                return selectedDocNums.has(docNum);
            }).length;

            $(selectAllSelector)
                .prop('checked', selectableDocNums.length > 0 && checkedOnPage === selectableDocNums.length)
                .prop('indeterminate', checkedOnPage > 0 && checkedOnPage < selectableDocNums.length);
        }

        function restoreSelectionState(api) {
            syncResponsiveControlColumn(api);
            pageCheckboxes(api).each(function () {
                const docNum = checkboxDocNum(this);
                $(this).prop('checked', docNum !== '' && selectedDocNums.has(docNum));
            });
            updateSelectAllState(api);
            updateBulkActionsUi();
        }

        function clearSelection(api) {
            selectedDocNums.clear();
            pageCheckboxes(api).prop('checked', false);
            updateSelectAllState(api);
            updateBulkActionsUi();
        }

        function protectStateColumns(data) {
            if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
                window.AppDataTables.protectStateColumns(data, protectedColumns);
                return;
            }

            [0, 1, 11].forEach(function (index) {
                if (data && data.columns && data.columns[index]) {
                    data.columns[index].visible = true;
                }
            });
        }

        function showProtectedColumns(api) {
            if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
                window.AppDataTables.showColumns(api, protectedColumns);
                return;
            }

            [0, 1, 11].forEach(function (index) {
                api.column(index).visible(true, false);
            });
            api.columns.adjust();
        }

        const branchesTable = $table.DataTable(dataTableOptions({
            processing: true,
            serverSide: true,
            stateSave: true,
            stateLoadParams: function (settings, data) { protectStateColumns(data); },
            stateSaveParams: function (settings, data) { protectStateColumns(data); },
            ajax: {
                url: $table.data('url'),
                data: function (data) {
                    data.trash_filter = trashFilterValue();
                }
            },
            responsive: { details: { type: 'inline', target: responsiveControlTarget } },
            order: [[1, 'desc']],
            columns: [
                { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
                { data: 'doc_num', name: 'branches.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
                { data: 'name', name: 'name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'company', name: 'company', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'type', name: 'type', className: 'align-middle white-space-nowrap' },
                { data: 'contact', name: 'contact', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'status', name: 'status', className: 'align-middle white-space-nowrap' },
                { data: 'created_by', name: 'created_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'created_at', name: 'created_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'updated_by', name: 'updated_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'updated_at', name: 'updated_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap all no-colvis dt-actions' }
            ],
            columnDefs: [
                { className: 'dt-select no-colvis all', orderable: false, responsivePriority: 1, searchable: false, targets: 0 },
                { className: 'dt-code no-colvis all dtr-control', responsivePriority: 2, targets: 1 },
                { className: 'dt-actions no-colvis all', orderable: false, responsivePriority: 3, searchable: false, targets: -1 },
                { responsivePriority: 10, targets: [2, 3, 4] },
                { responsivePriority: 20, targets: [5, 6] },
                { responsivePriority: 30, targets: [7, 8, 9, 10] }
            ],
            createdRow: function (row) { $(row).addClass('btn-reveal-trigger'); },
            initComplete: function () {
                showProtectedColumns(this.api());
                restoreSelectionState(this.api());
            },
            drawCallback: function () {
                restoreSelectionState(this.api());
                if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
                    window.AppDataTables.applyFalconEnhancements(document);
                }
            }
        }));

        patchResponsiveControlTarget(branchesTable);
        queueResponsiveControlSync(branchesTable);

        branchesTable.off('draw.dt.branchesResponsive column-visibility.dt.branchesResponsive column-sizing.dt.branchesResponsive responsive-resize.dt.branchesResponsive')
            .on('draw.dt.branchesResponsive column-visibility.dt.branchesResponsive column-sizing.dt.branchesResponsive responsive-resize.dt.branchesResponsive', function () {
                queueResponsiveControlSync(branchesTable);
            });

        function reloadTable() {
            branchesTable.ajax.reload(null, false);
        }

        $(trashFilterSelector).off('change.branchesTrashFilter').on('change.branchesTrashFilter', function () {
            clearSelection(branchesTable);
            reloadTable();
        });

        $(selectAllSelector).off('change.branchesSelect').on('change.branchesSelect', function () {
            const checked = $(this).is(':checked');

            pageCheckboxes(branchesTable).each(function () {
                const docNum = checkboxDocNum(this);
                if (docNum === '') { return; }
                if (checked) { selectedDocNums.add(docNum); } else { selectedDocNums.delete(docNum); }
                $(this).prop('checked', checked);
            });

            updateSelectAllState(branchesTable);
            updateBulkActionsUi();
        });

        $table.off('click.branchesSelectCell', 'tbody tr:not(.child) td.dt-select').on('click.branchesSelectCell', 'tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            const $checkbox = $(this).find('input.js-record-select, input.js-branch-row-checkbox').first();
            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        });

        $table.off('dblclick.branchesEditRow', 'tbody tr:not(.child)').on('dblclick.branchesEditRow', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            const editLink = $(this).find('.js-edit-record').get(0);
            if (editLink) { editLink.click(); }
        });

        $(document).off('click.branchesSelectStop mousedown.branchesSelectStop mouseup.branchesSelectStop', '.js-record-select, #select_all_records, td.dt-select')
            .on('click.branchesSelectStop mousedown.branchesSelectStop mouseup.branchesSelectStop', '.js-record-select, #select_all_records, td.dt-select', function (event) {
                event.stopPropagation();
            });

        $table.off('change.branchesSelect', rowCheckboxSelector).on('change.branchesSelect', rowCheckboxSelector, function () {
            const docNum = checkboxDocNum(this);
            if (docNum === '') { return; }
            if ($(this).is(':checked')) { selectedDocNums.add(docNum); } else { selectedDocNums.delete(docNum); }
            updateSelectAllState(branchesTable);
            updateBulkActionsUi();
        });

        $(document).off('branches:deleted.branchesTable branches:restored.branchesTable').on('branches:deleted.branchesTable branches:restored.branchesTable', function (event, docNum) {
            if (docNum) {
                selectedDocNums.delete(docNum);
            }

            reloadTable();
            updateSelectAllState(branchesTable);
            updateBulkActionsUi();
        });

        $('#bulk_action_apply').off('click.branchesBulk').on('click.branchesBulk', function () {
            const docNums = Array.from(selectedDocNums);

            if (docNums.length === 0 || $('#bulk_action_select').val() !== 'delete') {
                updateBulkActionsUi();
                return;
            }

            confirmDialog({
                title: messages.bulkDeleteConfirmTitle,
                text: (messages.bulkDeleteConfirmText || '').replace(':count', docNums.length),
                confirmButtonText: messages.bulkDeleteConfirmYes
            }).then(function (result) {
                if (!result.isConfirmed) { return; }

                $.ajax({
                    url: $table.data('bulk-delete-url'),
                    method: 'DELETE',
                    data: { doc_nums: docNums },
                    headers: headers()
                }).done(function (response) {
                    clearSelection(branchesTable);
                    reloadTable();
                    showToast('success', response.message);
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                });
            });
        });
    }

    function branchesTableApi() {
        const $table = $('#branches-table');

        if ($table.length === 0 || !$.fn.DataTable || !$.fn.DataTable.isDataTable($table[0])) {
            return null;
        }

        return $table.DataTable();
    }

    function initBranchRecordActions() {
        $(document).off('click.branchesDelete', '[data-branch-delete-url], .js-delete-record[data-delete-url]').on('click.branchesDelete', '[data-branch-delete-url], .js-delete-record[data-delete-url]', function () {
            const $button = $(this);
            const url = $button.data('branch-delete-url') || $button.data('delete-url');
            const docNum = String($button.data('doc-num') || '').trim();

            if (!url) {
                showToast('error', messages.unexpectedError);
                return;
            }

            confirmDialog({
                title: messages.deleteConfirmTitle,
                text: messages.deleteConfirmText,
                confirmButtonText: messages.deleteConfirmYes
            }).then(function (result) {
                if (!result.isConfirmed) { return; }

                setLoading($button, true);

                $.ajax({ url: url, method: 'DELETE', headers: headers() }).done(function (response) {
                    showToast('success', response.message);

                    if (branchesTableApi()) {
                        $(document).trigger('branches:deleted', [docNum]);
                        return;
                    }

                    window.location.href = $button.data('redirect-url') || $('[data-shortcut-action="form.back"]').attr('href') || '/admin/branches';
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                }).always(function () {
                    setLoading($button, false);
                });
            });
        });

        $(document).off('click.branchesRestore', '[data-branch-restore-url], .js-restore-record[data-restore-url]').on('click.branchesRestore', '[data-branch-restore-url], .js-restore-record[data-restore-url]', function () {
            const $button = $(this);
            const url = $button.data('branch-restore-url') || $button.data('restore-url');
            const docNum = String($button.data('doc-num') || '').trim();

            if (!url) {
                showToast('error', messages.unexpectedError);
                return;
            }

            confirmDialog({
                title: messages.restoreConfirmTitle,
                text: messages.restoreConfirmText,
                confirmButtonText: messages.restoreConfirmYes || messages.restore,
                confirmButtonColor: '#00a65a'
            }).then(function (result) {
                if (!result.isConfirmed) { return; }

                setLoading($button, true);

                $.ajax({
                    url: url,
                    method: 'PATCH',
                    headers: headers()
                }).done(function (response) {
                    showToast('success', response.message);

                    if (branchesTableApi()) {
                        $(document).trigger('branches:restored', [docNum]);
                        return;
                    }

                    window.location.reload();
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                }).always(function () {
                    setLoading($button, false);
                });
            });
        });
    }

    function initBranchForm() {
        $('.js-branch-form').each(function () {
            syncBranchDetailTabs($(this));
            initSelect2(this);
        });
        initBranchDetailShortcuts();

        $(document).off('click.branchesSubmitAction', '.js-branch-submit-action').on('click.branchesSubmitAction', '.js-branch-submit-action', function () {
            const $button = $(this);
            $button.closest('form').find('[name="submit_action"]').val(String($button.data('submit-action') || 'save'));
            $button.closest('form').data('submit-button', $button);
        });

        $(document).off('change.branchesType', '.js-branch-form [name="type"]').on('change.branchesType', '.js-branch-form [name="type"]', function () {
            syncBranchDetailTabs($(this).closest('form'));
        });

        $(document).off('click.branchesAddHall', '.js-add-station-hall').on('click.branchesAddHall', '.js-add-station-hall', function () {
            const $form = $(this).closest('form');
            const $list = $form.find('.js-station-halls-list');
            const nextIndex = nextStationHallIndex($form);

            $list.append(stationHallRow(nextIndex, {}));
            $list.find('.js-station-hall-input').last().trigger('focus');
        });

        $(document).off('click.branchesRemoveHall', '.js-remove-station-hall').on('click.branchesRemoveHall', '.js-remove-station-hall', function () {
            const $form = $(this).closest('form');
            const $row = $(this).closest('.js-station-hall-row');

            $row.remove();
            ensureStationHallRow($form);
            $form.find('[data-error-for="station_halls"]').text('');
        });

        $(document).off('click.branchesAddStore', '.js-add-branch-store').on('click.branchesAddStore', '.js-add-branch-store', function () {
            const $form = $(this).closest('form');
            const $list = $form.find('.js-branch-stores-list');
            const nextIndex = nextBranchStoreIndex($form);

            $list.append(branchStoreRow(nextIndex, {}));
            $list.find('.js-branch-store-input').last().trigger('focus');
        });

        $(document).off('click.branchesRemoveStore', '.js-remove-branch-store').on('click.branchesRemoveStore', '.js-remove-branch-store', function () {
            const $form = $(this).closest('form');
            const $row = $(this).closest('.js-branch-store-row');

            $row.remove();
            ensureBranchStoreRow($form);
            $form.find('[data-error-for="branch_stores"]').text('');
        });

        $(document).off('submit.branchesForm', '.js-branch-form').on('submit.branchesForm', '.js-branch-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $button = $form.data('submit-button') || $form.find('[type="submit"]').first();

            clearFormErrors($form);

            if ($form.find('.js-select2-ajax').filter(function () { return $(this).data('select2HydratingSelected') === true; }).length > 0) {
                showFormNotice($form, messages.loading, 'info');
                return;
            }

            if (!hasChanges($form)) {
                showFormNotice($form, messages.noChanges, 'warning');
                showToast('info', messages.noChanges);
                return;
            }

            setLoading($button, true);

            $.ajax({
                url: $form.attr('action'),
                method: $form.find('[name="_method"]').val() || $form.attr('method') || 'POST',
                data: $form.serialize(),
                headers: headers()
            }).done(function (response) {
                if (response && response.success === false && response.type === 'no_changes') {
                    showFormNotice($form, response.message || messages.noChanges, 'warning');
                    showToast('info', response.message || messages.noChanges);
                    return;
                }

                updateUrlsAfterDocNumberChange($form, response);
                updateOriginalFormData($form);
                showToast('success', response.message);

                if (response && response.reset_form) {
                    resetBranchCreateForm($form, response);
                }

                if (response && response.redirect) {
                    window.location.href = response.redirect;
                }
            }).fail(function (response) {
                if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                    showValidationErrors($form, response.responseJSON.errors);
                    return;
                }

                showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError, 'danger');
            }).always(function () {
                setLoading($button, false);
            });
        });

        $(document).off('input.branchesForm change.branchesForm', '.js-branch-form .is-invalid').on('input.branchesForm change.branchesForm', '.js-branch-form .is-invalid', function () {
            const $input = $(this);
            const field = ($input.attr('name') || '').replace('[]', '');
            const dotField = errorFieldFromName(field);

            $input.removeClass('is-invalid');
            $input.closest('form').find('[data-error-for="' + field + '"], [data-error-for="' + dotField + '"]').text('');
        });
    }

    function initDocumentNumberSettings() {
        $(document).off('submit.branchesDocSettings', '.js-branch-document-number-settings-form').on('submit.branchesDocSettings', '.js-branch-document-number-settings-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $button = $form.find('[type="submit"]');

            clearFormErrors($form);
            setLoading($button, true);

            $.ajax({
                url: $form.attr('action'),
                method: $form.find('[name="_method"]').val() || $form.attr('method') || 'POST',
                data: $form.serialize(),
                headers: headers()
            }).done(function (response) {
                const data = response && response.data ? response.data : {};

                if (Object.prototype.hasOwnProperty.call(data, 'prefix')) {
                    $form.find('[name="prefix"]').val(data.prefix || '');
                }

                if (Object.prototype.hasOwnProperty.call(data, 'padding')) {
                    $form.find('[name="padding"]').val(data.padding);
                }

                showToast('success', response.message);
            }).fail(function (response) {
                if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                    showValidationErrors($form, response.responseJSON.errors);
                    return;
                }

                showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError, 'danger');
            }).always(function () {
                setLoading($button, false);
            });
        });
    }

    initBranchesTable();
    initBranchRecordActions();
    initBranchForm();
    initDocumentNumberSettings();
})(jQuery, window);
