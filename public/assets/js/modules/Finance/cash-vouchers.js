(function ($) {
    'use strict';

    const messages = window.cashVoucherMessages || {};

    function trans(key, fallback) {
        return messages[key] || fallback;
    }

    function headers() {
        return {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            Accept: 'application/json'
        };
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function showToast(icon, title) {
        if (window.financeShowToast) {
            window.financeShowToast(icon, title);
            return;
        }

        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
        }
    }

    function formatAmount(value) {
        const numeric = Number(value) || 0;
        const rounded = Math.round((numeric + Number.EPSILON) * 10000) / 10000;

        return rounded.toFixed(4).replace(/\.?0+$/, '') || '0';
    }

    function parseAmount(value) {
        return parseFloat(String(value || '').replace(/,/g, '')) || 0;
    }

    function initSelect2(root) {
        if (!window.AppSelect2Ajax || typeof window.AppSelect2Ajax.init !== 'function') {
            return;
        }

        window.AppSelect2Ajax.init(root || document);
    }

    function syncExchangeRate($form) {
        const mainCurrencyDocNum = String($form.data('main-currency-doc-num') || '');
        const $currency = $form.find('[name="currency_doc_num"]');
        const $exchangeRate = $form.find('[name="exchange_rate"]');
        const selectedOption = $currency.find('option:selected');
        const isMainByPayload = String(selectedOption.data('is-main') || '') === '1' || selectedOption.data('is-main') === true;
        const isMainByDocNum = mainCurrencyDocNum !== '' && String($currency.val() || '') === mainCurrencyDocNum;

        if ($currency.length === 0 || $exchangeRate.length === 0) {
            return;
        }

        if (isMainByPayload || isMainByDocNum) {
            $exchangeRate.val('1').prop('readonly', true);
            return;
        }

        $exchangeRate.prop('readonly', false);
    }

    function renumberLines($form) {
        $form.find('.js-cash-voucher-line').each(function (index) {
            const $row = $(this);
            $row.attr('data-index', index);
            $row.find('[name]').each(function () {
                const $field = $(this);
                $field.attr('name', String($field.attr('name')).replace(/lines\[\d+\]/, 'lines[' + index + ']'));
            });
            $row.find('[data-error-for]').each(function () {
                const $error = $(this);
                $error.attr('data-error-for', String($error.attr('data-error-for')).replace(/lines\.\d+\./, 'lines.' + index + '.'));
            });
        });
    }

    function lineTemplate($form, values) {
        const rowValues = values || {};
        const index = $form.find('.js-cash-voucher-line').length;
        const accountValue = rowValues.account_doc_num || '';
        const accountLabel = rowValues.account_label || accountValue;
        const accountOption = accountValue !== '' ? '<option value="' + escapeHtml(accountValue) + '" selected>' + escapeHtml(accountLabel) + '</option>' : '';
        const amount = rowValues.amount == null ? '' : String(rowValues.amount);
        const description = rowValues.description == null ? '' : String(rowValues.description);
        const notes = rowValues.notes == null ? '' : String(rowValues.notes);

        return [
            '<tr class="js-cash-voucher-line" data-index="' + index + '">',
            '<td><select class="form-select js-select2-ajax js-cash-voucher-account" name="lines[' + index + '][account_doc_num]" data-url="' + escapeHtml($form.data('account-url') || '') + '" data-placeholder="' + escapeHtml(trans('select_account', 'Select Account')) + '" data-allow-clear="true" data-extra-params=\'{"exclude":"#cashbox_account_doc_num_filter"}\' required>' + accountOption + '</select><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.account_doc_num"></div></td>',
            '<td><input class="form-control text-end js-cash-voucher-line-amount" name="lines[' + index + '][amount]" type="number" min="0.0001" step="0.0001" value="' + escapeHtml(amount) + '" dir="ltr" required><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.amount"></div></td>',
            '<td><input class="form-control" name="lines[' + index + '][description]" value="' + escapeHtml(description) + '"><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.description"></div></td>',
            '<td><input class="form-control" name="lines[' + index + '][notes]" value="' + escapeHtml(notes) + '"><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.notes"></div></td>',
            '<td class="text-center"><button class="btn btn-link text-600 p-0 me-2 js-cash-voucher-duplicate-line" type="button" title="' + escapeHtml(trans('duplicate_line_title', 'Duplicate row')) + '" data-bs-title="' + escapeHtml(trans('duplicate_line_title', 'Duplicate row')) + '"><span class="fas fa-copy"></span></button><button class="btn btn-link text-danger p-0 js-cash-voucher-remove-line" type="button" title="' + escapeHtml(trans('delete_line_title', 'Delete row')) + '" data-bs-title="' + escapeHtml(trans('delete_line_title', 'Delete row')) + '"><span class="fas fa-trash-alt"></span></button></td>',
            '</tr>'
        ].join('');
    }

    function rowValues($row) {
        const $account = $row.find('.js-cash-voucher-account');

        return {
            account_doc_num: $account.val() || '',
            account_label: $account.find('option:selected').text() || '',
            amount: $row.find('.js-cash-voucher-line-amount').val() || '',
            description: $row.find('input[name$="[description]"]').val() || '',
            notes: $row.find('input[name$="[notes]"]').val() || ''
        };
    }

    function calculateTotals($form) {
        const voucherAmount = parseAmount($form.find('[name="amount"]').val());
        let distributed = 0;

        $form.find('.js-cash-voucher-line-amount').each(function () {
            distributed += parseAmount($(this).val());
        });

        const remaining = voucherAmount - distributed;

        $form.find('.js-cash-voucher-total-distributed').text(formatAmount(distributed));
        $form.find('.js-cash-voucher-remaining')
            .text(formatAmount(remaining))
            .toggleClass('text-danger', remaining < -0.0001)
            .toggleClass('text-success', remaining >= -0.0001);

        return remaining >= -0.0001;
    }

    function addLineAfter($form, $afterRow, values) {
        const $row = $(lineTemplate($form, values));

        if ($afterRow && $afterRow.length > 0) {
            $afterRow.after($row);
        } else {
            $form.find('.js-cash-voucher-lines tbody').append($row);
        }

        renumberLines($form);
        initSelect2($row[0]);
        calculateTotals($form);
        if ($row.find('.js-cash-voucher-account').data('select2')) {
            $row.find('.js-cash-voucher-account').select2('open');
        }

        return $row;
    }

    function clearRow($form, $row) {
        $row.find('.js-cash-voucher-account').val(null).trigger('change');
        $row.find('.js-cash-voucher-line-amount').val('');
        $row.find('input[name$="[description]"], input[name$="[notes]"]').val('');
        renumberLines($form);
        calculateTotals($form);
    }

    function removeRow($form, $row) {
        const $rows = $form.find('.js-cash-voucher-line');

        if ($rows.length <= 1) {
            clearRow($form, $row);
            return;
        }

        $row.remove();
        renumberLines($form);
        calculateTotals($form);
    }

    function syncCashboxAccount($form, data) {
        const accountDocNum = data && data.account_doc_num ? String(data.account_doc_num) : '';

        $form.data('cashbox-account-doc-num', accountDocNum).attr('data-cashbox-account-doc-num', accountDocNum);
        $form.find('.js-cash-voucher-cashbox-account-doc-num').val(accountDocNum);

        $form.find('.js-cash-voucher-account').each(function () {
            const $account = $(this);

            if (String($account.val() || '') === accountDocNum) {
                $account.val(null).trigger('change');
            }
        });
    }

    function resetCurrency($form) {
        const $currency = $form.find('.js-cash-voucher-currency');

        $currency.val(null).trigger('change');
        $currency.prop('disabled', !($form.find('[name="cashbox_doc_num"]').val()));
        syncExchangeRate($form);
    }

    function maybeSelectSingleCurrency($form) {
        const cashbox = $form.find('[name="cashbox_doc_num"]').val();
        const $currency = $form.find('.js-cash-voucher-currency');

        if (!cashbox || $currency.val()) {
            return;
        }

        $.ajax({
            url: $form.data('currency-url'),
            method: 'GET',
            data: { cashbox: cashbox, page: 1 },
            headers: headers()
        }).done(function (response) {
            const results = response && response.results ? response.results : [];

            if (results.length !== 1) {
                return;
            }

            const option = results[0];
            const htmlOption = new Option(option.text, option.id, true, true);
            $(htmlOption).attr('data-is-main', option.is_main ? '1' : '0');
            $currency.append(htmlOption).trigger('change');
            syncExchangeRate($form);
        });
    }

    function initForm() {
        $('.js-cash-voucher-form').each(function () {
            const $form = $(this);

            initSelect2($form[0]);
            syncExchangeRate($form);
            calculateTotals($form);
        });

        $(document)
            .off('select2:select.cashVoucherCashbox change.cashVoucherCashbox', '.js-cash-voucher-cashbox')
            .on('select2:select.cashVoucherCashbox change.cashVoucherCashbox', '.js-cash-voucher-cashbox', function (event) {
                const $form = $(this).closest('.js-cash-voucher-form');
                const data = event.params && event.params.data ? event.params.data : {
                    account_doc_num: $(this).find('option:selected').data('account-doc-num') || ''
                };

                syncCashboxAccount($form, data);
                resetCurrency($form);
                maybeSelectSingleCurrency($form);
            })
            .off('select2:clear.cashVoucherCashbox', '.js-cash-voucher-cashbox')
            .on('select2:clear.cashVoucherCashbox', '.js-cash-voucher-cashbox', function () {
                const $form = $(this).closest('.js-cash-voucher-form');

                syncCashboxAccount($form, {});
                resetCurrency($form);
            })
            .off('select2:select.cashVoucherCurrency change.cashVoucherCurrency', '.js-cash-voucher-currency')
            .on('select2:select.cashVoucherCurrency change.cashVoucherCurrency', '.js-cash-voucher-currency', function (event) {
                const $form = $(this).closest('.js-cash-voucher-form');
                const data = event.params && event.params.data ? event.params.data : null;

                if (data && data.is_main !== undefined) {
                    $(this).find('option:selected').attr('data-is-main', data.is_main ? '1' : '0');
                }

                syncExchangeRate($form);
            });

        $(document)
            .off('click.cashVoucherAddLine', '.js-cash-voucher-add-line')
            .on('click.cashVoucherAddLine', '.js-cash-voucher-add-line', function () {
                addLineAfter($(this).closest('.js-cash-voucher-form'), null, null);
            })
            .off('click.cashVoucherDuplicateLine', '.js-cash-voucher-duplicate-line')
            .on('click.cashVoucherDuplicateLine', '.js-cash-voucher-duplicate-line', function () {
                const $row = $(this).closest('.js-cash-voucher-line');
                addLineAfter($(this).closest('.js-cash-voucher-form'), $row, rowValues($row));
            })
            .off('click.cashVoucherRemoveLine', '.js-cash-voucher-remove-line')
            .on('click.cashVoucherRemoveLine', '.js-cash-voucher-remove-line', function () {
                removeRow($(this).closest('.js-cash-voucher-form'), $(this).closest('.js-cash-voucher-line'));
            })
            .off('input.cashVoucherTotals change.cashVoucherTotals', '.js-cash-voucher-amount, .js-cash-voucher-line-amount')
            .on('input.cashVoucherTotals change.cashVoucherTotals', '.js-cash-voucher-amount, .js-cash-voucher-line-amount', function () {
                calculateTotals($(this).closest('.js-cash-voucher-form'));
            });

        $(document).off('submit.cashVoucherGuard', '.js-cash-voucher-form').on('submit.cashVoucherGuard', '.js-cash-voucher-form', function (event) {
            const $form = $(this);

            if (calculateTotals($form)) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            $form.find('[data-error-for="lines"]').text(trans('distribution_exceeds_amount', 'Distributed amount cannot exceed voucher amount.'));
        });

        $(document).off('cashVoucher:reset', '.js-cash-voucher-form').on('cashVoucher:reset', '.js-cash-voucher-form', function () {
            const $form = $(this);

            $form.find('[name="doc_number"], [name="amount"], [name="person_name"], [name="person_national_id"], [name="person_phone"], [name="reason"], [name="description"]').val('');
            $form.find('.js-cash-voucher-cashbox, .js-cash-voucher-currency').val(null).trigger('change');
            $form.find('.js-cash-voucher-currency').prop('disabled', true);
            $form.find('.js-cash-voucher-line').not(':first').remove();
            clearRow($form, $form.find('.js-cash-voucher-line').first());
            $form.find('[name="submit_action"]').val('save');
            $form.find('[name="clone_source_token"]').remove();
            $form.find('[data-error-for]').text('');
            $form.find('.is-invalid').removeClass('is-invalid');
        });
    }

    function reloadOrRefresh() {
        const $table = $('.js-finance-table');

        if ($table.length > 0 && $.fn.DataTable && $.fn.DataTable.isDataTable($table[0])) {
            $table.DataTable().ajax.reload(null, false);
            return;
        }

        window.location.reload();
    }

    function initLifecycleActions() {
        $(document).off('click.cashVoucherApprove', '.js-cash-voucher-approve').on('click.cashVoucherApprove', '.js-cash-voucher-approve', function () {
            const $button = $(this);
            const url = $button.data('url');
            const confirmRequest = window.Swal
                ? window.Swal.fire({
                    title: trans('approve_confirm_title', 'Approve voucher?'),
                    text: trans('approve_confirm_text', 'The voucher will be locked after approval.'),
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: trans('approve_confirm_yes', 'Approve'),
                    cancelButtonText: trans('cancel', 'Cancel')
                })
                : Promise.resolve({ isConfirmed: window.confirm(trans('approve_confirm_title', 'Approve voucher?')) });

            confirmRequest.then(function (result) {
                if (!result.isConfirmed || !url) {
                    return;
                }

                $button.prop('disabled', true);
                $.ajax({
                    url: url,
                    method: 'POST',
                    headers: headers()
                }).done(function (response) {
                    showToast('success', response.message);
                    reloadOrRefresh();
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : trans('unexpected_error', 'Unexpected error.'));
                }).always(function () {
                    $button.prop('disabled', false);
                });
            });
        });

        $(document).off('click.cashVoucherCancel', '.js-cash-voucher-cancel').on('click.cashVoucherCancel', '.js-cash-voucher-cancel', function () {
            const $button = $(this);
            const url = $button.data('url');
            const confirmRequest = window.Swal
                ? window.Swal.fire({
                    title: trans('cancel_confirm_title', 'Cancel voucher?'),
                    text: trans('cancel_confirm_text', 'Enter a cancellation reason.'),
                    input: 'textarea',
                    inputPlaceholder: trans('cancel_reason_placeholder', 'Cancellation reason'),
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: trans('cancel_confirm_yes', 'Cancel Voucher'),
                    cancelButtonText: trans('cancel', 'Cancel'),
                    inputValidator: function (value) {
                        if (!value || $.trim(value) === '') {
                            return trans('cancel_reason_placeholder', 'Cancellation reason');
                        }

                        return null;
                    }
                })
                : Promise.resolve({ isConfirmed: true, value: window.prompt(trans('cancel_reason_placeholder', 'Cancellation reason')) });

            confirmRequest.then(function (result) {
                if (!result.isConfirmed || !url) {
                    return;
                }

                $button.prop('disabled', true);
                $.ajax({
                    url: url,
                    method: 'POST',
                    data: { cancel_reason: result.value || '' },
                    headers: headers()
                }).done(function (response) {
                    showToast('success', response.message);
                    reloadOrRefresh();
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : trans('unexpected_error', 'Unexpected error.'));
                }).always(function () {
                    $button.prop('disabled', false);
                });
            });
        });
    }

    $(function () {
        initForm();
        initLifecycleActions();
    });
})(window.jQuery);
