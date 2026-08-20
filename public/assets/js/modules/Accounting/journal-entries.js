(function ($, window, document) {
    'use strict';

    const messages = window.journalEntryMessages || {};

    function message(key, fallback) {
        return messages[key] || fallback;
    }

    function csrf() {
        return $('meta[name="csrf-token"]').attr('content');
    }

    function toast(icon, text) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, text);
        }
    }

    function confirmAction(title, text, confirmText) {
        if (!window.Swal) {
            return $.Deferred().resolve({ isConfirmed: window.confirm(text) }).promise();
        }

        return Swal.fire({
            icon: 'warning',
            title: title,
            text: text,
            showCancelButton: true,
            focusCancel: true,
            confirmButtonText: confirmText,
            cancelButtonText: message('cancel', 'Cancel')
        });
    }

    function requestAction(url, method, confirmation, onSuccess) {
        confirmAction(confirmation.title, confirmation.text, confirmation.confirm).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }

            $.ajax({
                url: url,
                method: method,
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }
            }).done(function (response) {
                toast('success', response.message || message('saved', 'Saved successfully.'));
                if (typeof onSuccess === 'function') {
                    onSuccess(response);
                }
            }).fail(function (xhr) {
                const errors = xhr.responseJSON && xhr.responseJSON.errors ? xhr.responseJSON.errors : {};
                const first = Object.values(errors).flat()[0];
                toast('error', first || (xhr.responseJSON && xhr.responseJSON.message) || message('unexpected_error', 'Unexpected error.'));
            });
        });
    }

    function initIndex() {
        const $table = $('#journal-entries-table');
        if (!$table.length || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const baseOptions = {
            ajax: {
                url: $table.data('url'),
                data: function (data) {
                    data.trash_filter = $('#journal_entries_trash_filter').val() || 'active';
                }
            },
            processing: true,
            serverSide: true,
            stateSave: true,
            order: [[2, 'desc'], [1, 'desc']],
            columns: [
                { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis text-center' },
                { data: 'doc_num', name: 'journal_entries.doc_number', className: 'dt-code no-colvis' },
                { data: 'entry_date', name: 'journal_entries.entry_date', className: 'dt-date' },
                { data: 'reference_no', name: 'journal_entries.reference_no', defaultContent: '', className: 'dt-code' },
                { data: 'status', name: 'journal_entries.status' },
                { data: 'is_system_generated', name: 'journal_entries.is_system_generated' },
                { data: 'total_debit', name: 'total_debit', searchable: false, className: 'dt-number text-end' },
                { data: 'total_credit', name: 'total_credit', searchable: false, className: 'dt-number text-end' },
                { data: 'created_by', name: 'created_by', className: 'dt-text dt-ellipsis' },
                { data: 'created_at', name: 'journal_entries.created_at', className: 'dt-date' },
                { data: 'updated_by', name: 'updated_by', className: 'dt-text dt-ellipsis' },
                { data: 'updated_at', name: 'journal_entries.updated_at', className: 'dt-date' },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'dt-actions no-colvis text-end' }
            ]
        };
        const options = window.AppDataTables && typeof window.AppDataTables.wideOptions === 'function'
            ? window.AppDataTables.wideOptions(baseOptions)
            : baseOptions;

        $table.DataTable(window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options(options)
            : options);

        $('#journal_entries_trash_filter').on('change', function () {
            $table.DataTable().ajax.reload(null, true);
        });

        $(document).on('click', '.js-journal-entry-post', function () {
            requestAction($(this).data('url'), 'POST', {
                title: message('post_title', 'Post journal entry?'),
                text: message('post_text', 'Posted entries cannot be edited or deleted.'),
                confirm: message('post_confirm', 'Post')
            }, function (response) {
                if (response.redirect && $('.js-journal-entry-form').length) {
                    window.location.assign(response.redirect);
                    return;
                }
                $table.DataTable().ajax.reload(null, false);
            });
        });

        $(document).on('click', '.js-journal-entry-delete', function () {
            requestAction($(this).data('url'), 'DELETE', {
                title: message('delete_title', 'Delete journal entry?'),
                text: message('delete_text', 'Only this draft will be moved to trash.'),
                confirm: message('delete_confirm', 'Delete')
            }, function () { $table.DataTable().ajax.reload(null, false); });
        });

        $(document).on('click', '.js-journal-entry-restore', function () {
            requestAction($(this).data('url'), 'PATCH', {
                title: message('restore_title', 'Restore journal entry?'),
                text: message('restore_text', 'The draft will return to active records.'),
                confirm: message('restore_confirm', 'Restore')
            }, function () { $table.DataTable().ajax.reload(null, false); });
        });
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function lineTemplate(index) {
        function select(kind, name, required) {
            return '<select class="form-select form-select-sm js-journal-entry-select" name="lines[' + index + '][' + name + ']" data-kind="' + kind + '"' + (required ? ' required' : ' data-allow-clear="true"') + '></select><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.' + name + '"></div>';
        }

        function amount(name, value) {
            return '<input class="form-control form-control-sm text-end js-journal-entry-amount" name="lines[' + index + '][' + name + ']" value="' + value + '" inputmode="decimal" dir="ltr"><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.' + name + '"></div>';
        }

        return [
            '<tr class="js-journal-entry-line" data-index="' + index + '">',
            '<td data-line-field="account">' + select('account', 'account_doc_num', true) + '</td>',
            '<td>' + amount('debit_amount', '0') + '</td>',
            '<td>' + amount('credit_amount', '0') + '</td>',
            '<td><input class="form-control form-control-sm" name="lines[' + index + '][description]"><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.description"></div></td>',
            '<td data-line-field="cost_center">' + select('cost_center', 'cost_center_doc_num', false) + '</td>',
            '<td data-line-field="customer">' + select('customer', 'customer_doc_num', false) + '</td>',
            '<td data-line-field="supplier">' + select('supplier', 'supplier_doc_num', false) + '</td>',
            '<td data-line-field="employee">' + select('employee', 'employee_doc_num', false) + '</td>',
            '<td class="text-center"><button class="btn btn-link text-danger p-0 js-journal-entry-remove-line" type="button" aria-label="' + escapeHtml(message('delete_line', 'Delete line')) + '"><span class="fas fa-trash-alt"></span></button></td>',
            '</tr>'
        ].join('');
    }

    function initSelect($select, $form) {
        if (!$.fn.select2 || $select.data('select2')) {
            return;
        }

        const kind = String($select.data('kind') || '');
        const urlMap = {
            account: $form.data('accounts-url'),
            cost_center: $form.data('cost-centers-url'),
            customer: $form.data('customers-url'),
            supplier: $form.data('suppliers-url'),
            employee: $form.data('employees-url')
        };

        $select.select2({
            theme: 'bootstrap-5',
            width: '100%',
            dir: document.documentElement.getAttribute('dir') || 'ltr',
            placeholder: message('select_' + kind, 'Select'),
            allowClear: $select.data('allow-clear') === true || String($select.data('allow-clear')) === 'true',
            ajax: {
                url: urlMap[kind],
                dataType: 'json',
                delay: 250,
                data: function (params) { return { q: params.term || '', page: params.page || 1 }; }
            }
        });
    }

    function renumber($form) {
        $form.find('.js-journal-entry-line').each(function (index) {
            const $row = $(this).attr('data-index', index);
            $row.find('[name]').each(function () {
                $(this).attr('name', String($(this).attr('name')).replace(/lines\[\d+\]/, 'lines[' + index + ']'));
            });
            $row.find('[data-error-for]').each(function () {
                $(this).attr('data-error-for', String($(this).attr('data-error-for')).replace(/lines\.\d+\./, 'lines.' + index + '.'));
            });
        });
    }

    function number(value) {
        if (window.AppNumbers && typeof window.AppNumbers.number === 'function') {
            return window.AppNumbers.number(value, 0);
        }
        return Number(String(value || 0).replace(/,/g, '')) || 0;
    }

    function format(value) {
        if (window.AppNumbers && typeof window.AppNumbers.format === 'function') {
            return window.AppNumbers.format(Number(value).toFixed(4).replace(/\.?0+$/, '') || '0');
        }
        return Number(value).toFixed(4);
    }

    function totals($form) {
        let debit = 0;
        let credit = 0;
        $form.find('.js-journal-entry-line').each(function () {
            debit += number($(this).find('[name$="[debit_amount]"]').val());
            credit += number($(this).find('[name$="[credit_amount]"]').val());
        });
        const difference = debit - credit;
        $form.find('.js-journal-entry-total-debit').text(format(debit));
        $form.find('.js-journal-entry-total-credit').text(format(credit));
        $form.find('.js-journal-entry-difference')
            .text(message('difference', 'Difference') + ': ' + format(difference))
            .toggleClass('text-danger', Math.abs(difference) >= 0.0001)
            .toggleClass('text-success', Math.abs(difference) < 0.0001);
    }

    function clearErrors($form) {
        $form.find('[data-error-for]').text('');
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('.js-journal-entry-alert').addClass('d-none').text('');
    }

    function showErrors($form, xhr) {
        const response = xhr.responseJSON || {};
        const errors = response.errors || {};
        Object.keys(errors).forEach(function (field) {
            $form.find('[data-error-for="' + field.replace(/"/g, '\\"') + '"]').text(errors[field][0] || '');
            $form.find('[name="' + field.replace(/\.(\d+)\./g, '[$1][').replace(/\.([^.]*)$/, '[$1]') + '"]').addClass('is-invalid');
        });
        $form.find('.js-journal-entry-alert').removeClass('d-none').text(response.message || message('validation_failed', 'Please review the highlighted fields.'));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function initForm() {
        const $form = $('.js-journal-entry-form');
        if (!$form.length) {
            return;
        }

        $form.find('.js-journal-entry-select').each(function () { initSelect($(this), $form); });
        totals($form);

        $form.on('click', '.js-journal-entry-post', function () {
            requestAction($(this).data('url'), 'POST', {
                title: message('post_title', 'Post journal entry?'),
                text: message('post_text', 'Posted entries cannot be edited or deleted.'),
                confirm: message('post_confirm', 'Post')
            }, function (response) {
                if (response.redirect) {
                    window.location.assign(response.redirect);
                }
            });
        });

        $form.on('click', '.js-journal-entry-add-line', function () {
            const index = $form.find('.js-journal-entry-line').length;
            const $row = $(lineTemplate(index)).appendTo($form.find('.js-journal-entry-lines tbody'));
            $row.find('.js-journal-entry-select').each(function () { initSelect($(this), $form); });
            totals($form);
        });

        $form.on('click', '.js-journal-entry-remove-line', function () {
            if ($form.find('.js-journal-entry-line').length <= 2) {
                toast('warning', message('minimum_lines', 'A journal entry requires at least two lines.'));
                return;
            }
            $(this).closest('.js-journal-entry-line').remove();
            renumber($form);
            totals($form);
        });

        $form.on('input', '.js-journal-entry-amount', function () {
            const $row = $(this).closest('.js-journal-entry-line');
            if (number($(this).val()) > 0) {
                const counterpart = $(this).attr('name').indexOf('[debit_amount]') !== -1 ? '[credit_amount]' : '[debit_amount]';
                $row.find('[name$="' + counterpart + '"]').val('0');
            }
            totals($form);
        });

        $form.on('submit', function (event) {
            event.preventDefault();
            clearErrors($form);
            const $submit = $form.find('[type="submit"]').prop('disabled', true);
            $.ajax({
                url: $form.attr('action'),
                method: $form.find('[name="_method"]').val() || $form.attr('method') || 'POST',
                data: $form.serialize(),
                headers: { Accept: 'application/json' }
            }).done(function (response) {
                toast(response.success === false ? 'info' : 'success', response.message || message('saved', 'Saved successfully.'));
                if (response.redirect) {
                    window.location.assign(response.redirect);
                }
            }).fail(function (xhr) {
                showErrors($form, xhr);
            }).always(function () {
                $submit.prop('disabled', false);
            });
        });
    }

    $(function () {
        initIndex();
        initForm();
    });
})(jQuery, window, document);
