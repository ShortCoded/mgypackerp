(function ($, window) {
    'use strict';

    const messages = window.screenDataVisibilityRuleMessages || {};
    const csrfToken = $('meta[name="csrf-token"]').attr('content');

    function headers() {
        return { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' };
    }

    function toast(icon, message) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, message);
        }
    }

    function confirmAction(title, text, confirmButtonText, color) {
        if (!window.Swal) {
            return $.Deferred().resolve({ isConfirmed: false }).promise();
        }

        return Swal.fire({
            icon: 'warning',
            title: title,
            text: text,
            showCancelButton: true,
            focusCancel: true,
            confirmButtonText: confirmButtonText,
            cancelButtonText: messages.no || '',
            confirmButtonColor: color || '#d33',
            cancelButtonColor: '#748194'
        });
    }

    function initSelect2() {
        const $user = $('.js-screen-visibility-user');
        const $screen = $('.js-screen-visibility-screen');

        if ($.fn.select2 && $user.length) {
            $user.select2({
                width: '100%',
                theme: 'bootstrap-5',
                placeholder: $user.data('placeholder'),
                allowClear: true,
                ajax: {
                    url: $user.data('url'),
                    dataType: 'json',
                    delay: 250,
                    data: function (params) { return { q: params.term || '', page: params.page || 1 }; },
                    processResults: function (response) { return response; }
                }
            });
        }

        if ($.fn.select2 && $screen.length) {
            $screen.select2({ width: '100%', theme: 'bootstrap-5', placeholder: $screen.data('placeholder'), allowClear: true });
        }
    }

    function preview() {
        const $form = $('.js-screen-visibility-rule-form');
        if (!$form.length) {
            return;
        }

        const original = $form.data('original') || {};
        const value = function (field, fallback) {
            const $field = $form.find('[name="' + field + '"]');

            return $field.length ? $field.val() : original[field] ?? fallback;
        };
        const scope = String(value('record_scope', 'own_records') || 'own_records');
        const maximum = String(value('max_visible_records', '') || '').trim();
        const duration = String(value('duration_value', '') || '').trim();
        const unit = String(value('duration_unit', '') || '').trim();
        const maximumText = maximum ? String(messages.previewLatest || '').replace(':count', maximum) : messages.previewAll;
        const unitLabel = messages.durationUnits && messages.durationUnits[unit] ? messages.durationUnits[unit] : unit;
        const durationText = duration && unit
            ? String(messages.previewDuring || '').replace(':value', duration).replace(':unit', unitLabel)
            : messages.previewAnyTime;
        const template = scope === 'authorized_scope' ? messages.previewAuthorized : messages.previewOwn;

        $form.find('.js-screen-visibility-preview').text(
            String(template || '').replace(':maximum', maximumText).replace(':duration', durationText)
        );
    }

    function clearErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $form.find('.js-screen-visibility-rule-alert').addClass('d-none');
    }

    function showErrors($form, response) {
        const payload = response.responseJSON || {};
        const errors = payload.errors || {};
        const lines = [];

        Object.keys(errors).forEach(function (field) {
            const values = Array.isArray(errors[field]) ? errors[field] : [errors[field]];
            const normalized = field.replace(/\.\d+$/, '');
            const first = values[0] || '';

            lines.push.apply(lines, values);
            $form.find('[name="' + normalized + '"]').addClass('is-invalid');
            $form.find('[data-error-for="' + normalized + '"]').text(first);
        });

        const $message = $form.find('.js-screen-visibility-rule-alert')
            .removeClass('d-none')
            .find('.js-screen-visibility-rule-alert-message')
            .empty();

        if (lines.length) {
            $message.append($('<ul class="mb-0 ps-3"></ul>').append(lines.map(function (line) { return $('<li></li>').text(line); })));
        } else {
            $message.text(payload.message || messages.unexpectedError);
        }
    }

    function formValues($form) {
        return {
            user_doc_num: String($form.find('[name="user_doc_num"]').val() || ''),
            screen_key: String($form.find('[name="screen_key"]').val() || ''),
            record_scope: String($form.find('[name="record_scope"]').val() || ''),
            max_visible_records: String($form.find('[name="max_visible_records"]').val() || ''),
            duration_value: String($form.find('[name="duration_value"]').val() || ''),
            duration_unit: String($form.find('[name="duration_unit"]').val() || ''),
            is_active: $form.find('[name="is_active"][type="checkbox"]').is(':checked'),
            notes: String($form.find('[name="notes"]').val() || '').trim()
        };
    }

    function hasChanges($form) {
        if ($form.data('mode') !== 'edit') {
            return true;
        }

        return JSON.stringify(formValues($form)) !== JSON.stringify($form.data('original') || {});
    }

    function initForm() {
        const $form = $('.js-screen-visibility-rule-form');
        if (!$form.length) {
            return;
        }

        $form.on('input change', '[name="record_scope"], [name="max_visible_records"], [name="duration_value"], [name="duration_unit"]', preview);
        preview();

        $(document).on('click.screenVisibilitySubmit', '.js-screen-visibility-submit', function () {
            const $button = $(this);
            $form.find('[name="submit_action"]').val(String($button.data('submit-action') || 'save'));
            $form.data('submit-button', $button);
        });

        $form.on('submit.screenVisibility', function (event) {
            event.preventDefault();
            clearErrors($form);

            if (!hasChanges($form)) {
                toast('info', messages.noChanges);
                return;
            }

            const $button = $form.data('submit-button') || $form.find('[type="submit"]').first();
            $button.prop('disabled', true);

            $.ajax({ url: $form.attr('action'), method: $form.attr('method') || 'POST', data: $form.serialize(), headers: headers() })
                .done(function (response) {
                    if (response.success === false) {
                        toast('info', response.message || messages.noChanges);
                        return;
                    }

                    toast('success', response.message);
                    if (response.redirect) {
                        window.location.href = response.redirect;
                        return;
                    }

                    if (response.reset_form) {
                        $form.get(0).reset();
                        $form.find('.js-screen-visibility-user, .js-screen-visibility-screen').val(null).trigger('change');
                        $form.find('[name="clone_source_token"]').remove();
                        preview();
                    } else {
                        $form.data('original', formValues($form));
                    }
                })
                .fail(function (response) { showErrors($form, response); })
                .always(function () { $button.prop('disabled', false); });
        });
    }

    function initTable() {
        const $table = $('.js-screen-visibility-rules-table');
        if (!$table.length || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const selected = new Set();
        const options = window.AppDataTables && typeof window.AppDataTables.options === 'function' ? window.AppDataTables.options : function (value) { return value; };
        const table = $table.DataTable(options({
            processing: true,
            serverSide: true,
            stateSave: true,
            ajax: { url: $table.data('url'), data: function (data) { data.trash_filter = $('#screen_visibility_rule_trash_filter').val() || 'active'; } },
            order: [[1, 'desc']],
            columns: [
                { data: 'checkbox', orderable: false, searchable: false },
                { data: 'doc_num', name: 'screen_data_visibility_rules.doc_number' },
                { data: 'user', name: 'rule_users.name' },
                { data: 'company', name: 'companies.name' },
                { data: 'module', name: 'screen_data_visibility_rules.screen_key' },
                { data: 'screen_key', name: 'screen_data_visibility_rules.screen_key' },
                { data: 'record_scope', name: 'screen_data_visibility_rules.record_scope' },
                { data: 'max_visible_records', name: 'screen_data_visibility_rules.max_visible_records' },
                { data: 'duration', name: 'screen_data_visibility_rules.duration_value' },
                { data: 'is_active', name: 'screen_data_visibility_rules.is_active' },
                { data: 'created_by', name: 'created_users.name' },
                { data: 'created_at', name: 'screen_data_visibility_rules.created_at' },
                { data: 'updated_by', name: 'updated_users.name' },
                { data: 'updated_at', name: 'screen_data_visibility_rules.updated_at' },
                { data: 'actions', orderable: false, searchable: false }
            ],
            columnDefs: [
                { targets: 0, className: 'dt-select no-colvis all text-center' },
                { targets: 1, className: 'dt-code no-colvis all dtr-control' },
                { targets: -1, className: 'dt-actions no-colvis all' }
            ],
            createdRow: function (row) { $(row).addClass('btn-reveal-trigger'); },
            drawCallback: function () {
                $table.find('.js-record-select').each(function () { $(this).prop('checked', selected.has(String($(this).data('doc-num')))); });
                updateBulk();
            }
        }));

        function updateBulk() {
            $('#bulk_selected_count').text(selected.size);
            $('#bulk_action_apply').prop('disabled', selected.size === 0);
            $('.screen-visibility-rule-bulk-actions-bar').toggleClass('d-none', selected.size === 0).toggleClass('d-flex', selected.size > 0);
        }

        $('#screen_visibility_rule_trash_filter').on('change', function () { selected.clear(); table.ajax.reload(null, false); });
        $('#select_all_records').on('change', function () {
            const checked = $(this).is(':checked');
            $table.find('.js-record-select').each(function () {
                const docNum = String($(this).data('doc-num'));
                checked ? selected.add(docNum) : selected.delete(docNum);
                $(this).prop('checked', checked);
            });
            updateBulk();
        });
        $table.on('change', '.js-record-select', function () {
            const docNum = String($(this).data('doc-num'));
            $(this).is(':checked') ? selected.add(docNum) : selected.delete(docNum);
            updateBulk();
        });
        $table.on('dblclick', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('a, button, input, .dropdown').length) { return; }
            const href = $(this).find('.js-edit-record').attr('href');
            if (href) { window.location.href = href; }
        });
        $('#bulk_action_apply').on('click', function () {
            if (!selected.size) { toast('info', messages.noRowsSelected); return; }
            confirmAction(messages.bulkDeleteConfirmTitle, String(messages.bulkDeleteConfirmText || '').replace(':count', selected.size), messages.bulkDeleteConfirmYes)
                .then(function (result) {
                    if (!result.isConfirmed) { return; }
                    $.ajax({ url: $table.data('bulk-delete-url'), method: 'DELETE', data: { doc_nums: Array.from(selected) }, headers: headers() })
                        .done(function (response) { selected.clear(); table.ajax.reload(null, false); toast('success', response.message); })
                        .fail(function (response) { toast('error', response.responseJSON && response.responseJSON.message || messages.unexpectedError); });
                });
        });
    }

    function initRecordActions() {
        $(document).on('click.screenVisibilityDelete', '.js-delete-record[data-delete-url]', function () {
            const $button = $(this);
            confirmAction(messages.deleteConfirmTitle, messages.deleteConfirmText, messages.deleteConfirmYes).then(function (result) {
                if (!result.isConfirmed) { return; }
                $.ajax({ url: $button.data('delete-url'), method: 'DELETE', headers: headers() })
                    .done(function (response) {
                        toast('success', response.message);
                        if ($('.js-screen-visibility-rules-table').length) { $('.js-screen-visibility-rules-table').DataTable().ajax.reload(null, false); }
                        else { window.location.href = $button.data('redirect-url'); }
                    })
                    .fail(function (response) { toast('error', response.responseJSON && response.responseJSON.message || messages.unexpectedError); });
            });
        });

        $(document).on('click.screenVisibilityRestore', '.js-restore-record[data-restore-url]', function () {
            const $button = $(this);
            confirmAction(messages.restoreConfirmTitle, messages.restoreConfirmText, messages.restoreConfirmYes, '#00a65a').then(function (result) {
                if (!result.isConfirmed) { return; }
                $.ajax({ url: $button.data('restore-url'), method: 'PATCH', headers: headers() })
                    .done(function (response) {
                        toast('success', response.message);
                        if ($('.js-screen-visibility-rules-table').length) { $('.js-screen-visibility-rules-table').DataTable().ajax.reload(null, false); }
                        else { window.location.reload(); }
                    })
                    .fail(function (response) { toast('error', response.responseJSON && response.responseJSON.message || messages.unexpectedError); });
            });
        });
    }

    $(function () {
        initSelect2();
        initForm();
        initTable();
        initRecordActions();
    });
}(jQuery, window));
