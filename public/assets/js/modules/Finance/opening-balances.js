(function ($) {
    'use strict';

    const messages = window.openingBalanceMessages || {};

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

    function initSelect2($select) {
        if (!$.fn.select2 || $select.data('select2')) {
            return;
        }

        const select2Messages = {
            errorLoading: function () { return trans('select2_error_loading', 'The results could not be loaded.'); },
            inputTooShort: function () { return trans('select2_input_too_short', 'Please enter more characters.'); },
            loadingMore: function () { return trans('select2_loading_more', 'Loading more results...'); },
            noResults: function () { return trans('select2_no_results', 'No results found'); },
            removeItem: function () { return trans('select2_remove_item', 'Remove item'); },
            searching: function () { return trans('select2_searching', 'Searching...'); }
        };

        $select.select2({
            theme: 'bootstrap-5',
            width: '100%',
            dir: document.documentElement.getAttribute('dir') || 'ltr',
            allowClear: false,
            placeholder: $select.data('placeholder') || '',
            language: select2Messages,
            ajax: {
                url: $select.data('url'),
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        q: params.term || '',
                        page: params.page || 1
                    };
                }
            }
        });
    }

    function renumberLines($form) {
        $form.find('.js-opening-balance-line').each(function (index) {
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

    function formatAmount(value) {
        const numeric = window.AppNumbers.number(value, 0);
        const rounded = Math.round((numeric + Number.EPSILON) * 10000) / 10000;

        return window.AppNumbers.format(rounded.toFixed(4).replace(/\.?0+$/, '') || '0');
    }

    function isAltShortcut(event, codes, keyCodes, legacyKeys) {
        if (window.AppShortcuts && typeof window.AppShortcuts.isAltShortcut === 'function') {
            return !event.ctrlKey && window.AppShortcuts.isAltShortcut(event, codes, keyCodes, legacyKeys);
        }

        const key = String(event.key || '').toLowerCase();
        const code = event.code || '';
        const keyCode = Number(event.keyCode || event.which || 0);

        return event.altKey === true
            && !event.ctrlKey
            && !event.metaKey
            && !event.shiftKey
            && (
                codes.indexOf(code) !== -1
                || keyCodes.indexOf(keyCode) !== -1
                || legacyKeys.indexOf(key) !== -1
            );
    }

    function isEnter(event) {
        const code = event.code || '';
        const keyCode = Number(event.keyCode || event.which || 0);

        return event.key === 'Enter' || code === 'Enter' || code === 'NumpadEnter' || keyCode === 13;
    }

    function isAltDelete(event) {
        const code = event.code || '';
        const keyCode = Number(event.keyCode || event.which || 0);

        return event.altKey === true
            && !event.ctrlKey
            && !event.metaKey
            && !event.shiftKey
            && (event.key === 'Delete' || code === 'Delete' || keyCode === 46);
    }

    function lineTemplate($form, values) {
        const rowValues = values || {};
        const index = $form.find('.js-opening-balance-line').length;
        const accountUrl = $form.data('account-url');
        const accountValue = rowValues.account_doc_num || '';
        const accountLabel = rowValues.account_label || accountValue;
        const accountNormalBalance = rowValues.account_normal_balance || rowValues.normal_balance || '';
        const accountOption = accountValue !== '' ? '<option value="' + escapeHtml(accountValue) + '" data-normal-balance="' + escapeHtml(accountNormalBalance) + '" selected>' + escapeHtml(accountLabel) + '</option>' : '';
        const type = rowValues.transaction_type === 'credit' ? 'credit' : 'debit';
        const amount = rowValues.amount == null ? '' : String(rowValues.amount);
        const description = rowValues.description == null ? '' : String(rowValues.description);
        const hiddenFields = Object.keys(rowValues.hidden || {}).map(function (field) {
            return '<input type="hidden" name="lines[' + index + '][' + escapeHtml(field) + ']" value="' + escapeHtml(rowValues.hidden[field]) + '">';
        }).join('');

        return [
            '<tr class="js-opening-balance-line" data-index="' + index + '">',
            '<td>' + hiddenFields + '<select class="form-select js-opening-balance-account" name="lines[' + index + '][account_doc_num]" data-url="' + escapeHtml(accountUrl) + '" data-placeholder="' + escapeHtml(trans('select_account', 'Select Account')) + '" required>' + accountOption + '</select><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.account_doc_num"></div></td>',
            '<td><select class="form-select js-opening-balance-type" name="lines[' + index + '][transaction_type]" required><option value=""></option><option value="debit"' + (type === 'debit' ? ' selected' : '') + '>' + escapeHtml(trans('debit', 'Debit')) + '</option><option value="credit"' + (type === 'credit' ? ' selected' : '') + '>' + escapeHtml(trans('credit', 'Credit')) + '</option></select><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.transaction_type"></div></td>',
            '<td><input class="form-control text-end js-opening-balance-amount" name="lines[' + index + '][amount]" type="text" inputmode="decimal" min="0.0001" step="0.0001" value="' + escapeHtml(amount) + '" dir="ltr" data-numeric-input data-numeric-scale="4" data-numeric-min="0.0001" required><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.amount"></div></td>',
            '<td><input class="form-control" name="lines[' + index + '][description]" value="' + escapeHtml(description) + '"><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.description"></div></td>',
            '<td class="text-center"><button class="btn btn-link text-600 p-0 me-2 js-opening-balance-duplicate-line" type="button" title="' + escapeHtml(trans('duplicate_line_title', 'Duplicate current row (Alt + D)')) + '" data-bs-title="' + escapeHtml(trans('duplicate_line_title', 'Duplicate current row (Alt + D)')) + '"><span class="fas fa-copy"></span></button><button class="btn btn-link text-danger p-0 js-opening-balance-remove-line" type="button" title="' + escapeHtml(trans('delete_line_title', 'Delete current row (Alt + Delete)')) + '" data-bs-title="' + escapeHtml(trans('delete_line_title', 'Delete current row (Alt + Delete)')) + '"><span class="fas fa-trash-alt"></span></button></td>',
            '</tr>'
        ].join('');
    }

    function rowValues($row) {
        const $account = $row.find('.js-opening-balance-account');
        const selected = $account.find('option:selected');
        const hidden = {};

        $row.find('input[type="hidden"][name^="lines["]').each(function () {
            const match = String($(this).attr('name') || '').match(/^lines\[\d+\]\[([^\]]+)\]$/);

            if (match && match[1]) {
                hidden[match[1]] = $(this).val();
            }
        });

        return {
            account_doc_num: $account.val() || '',
            account_label: selected.text() || '',
            account_normal_balance: selected.data('normal-balance') || '',
            transaction_type: $row.find('.js-opening-balance-type').val() || 'debit',
            amount: $row.find('.js-opening-balance-amount').val() || '',
            description: $row.find('input[name$="[description]"]').val() || '',
            hidden: hidden
        };
    }

    function calculateTotals($form) {
        if ($form.find('.js-opening-balance-amount').length === 0) {
            return true;
        }

        let totalDebit = 0;
        let totalCredit = 0;

        $form.find('.js-opening-balance-line').each(function () {
            const $row = $(this);
            const type = String($row.find('.js-opening-balance-type').val() || '').toLowerCase();
            const amount = window.AppNumbers.number($row.find('.js-opening-balance-amount').val(), 0);

            if (type === 'debit') {
                totalDebit += amount;
            } else if (type === 'credit') {
                totalCredit += amount;
            }
        });

        const difference = totalDebit - totalCredit;
        $form.find('.js-opening-balance-total-debit').text(formatAmount(totalDebit));
        $form.find('.js-opening-balance-total-credit').text(formatAmount(totalCredit));
        $form.find('.js-opening-balance-difference')
            .text(formatAmount(difference))
            .toggleClass('text-danger', Math.abs(difference) >= 0.0001)
            .toggleClass('text-success', Math.abs(difference) < 0.0001);

        return Math.abs(difference) < 0.0001;
    }

    function initCurrencyExchangeRate() {
        function sync($form) {
            const mainCurrencyDocNum = String($form.data('main-currency-doc-num') || '');
            const $currency = $form.find('[name="currency_doc_num"]');
            const $exchangeRate = $form.find('[name="exchange_rate"]');

            if ($currency.length === 0 || $exchangeRate.length === 0) {
                return;
            }

            const isMainCurrency = mainCurrencyDocNum !== '' && String($currency.val() || '') === mainCurrencyDocNum;

            if (isMainCurrency) {
                $exchangeRate.val('1').prop('readonly', true);
                return;
            }

            $exchangeRate.prop('readonly', false);
        }

        $('.js-opening-balance-form').each(function () {
            sync($(this));
        });

        $(document)
            .off('change.openingBalancesCurrency select2:select.openingBalancesCurrency select2:clear.openingBalancesCurrency', '.js-opening-balance-form [name="currency_doc_num"]')
            .on('change.openingBalancesCurrency select2:select.openingBalancesCurrency select2:clear.openingBalancesCurrency', '.js-opening-balance-form [name="currency_doc_num"]', function () {
                sync($(this).closest('.js-opening-balance-form'));
            });
    }

    function initLineGrid() {
        const $form = $('.js-opening-balance-form');
        if ($form.length === 0) {
            return;
        }

        $form.find('.js-opening-balance-account').each(function () {
            initSelect2($(this));
        });
        calculateTotals($form);
        let $lastFocusedRow = $();

        function focusAccountField($row) {
            const $account = $row.find('.js-opening-balance-account');

            if ($account.length > 0 && $.fn.select2) {
                $account.select2('open');
                return;
            }

            $account.trigger('focus');
        }

        function focusUsefulField($row) {
            const $amount = $row.find('.js-opening-balance-amount');

            if ($amount.length > 0) {
                $amount.trigger('focus').trigger('select');
                return;
            }

            $row.find(':input:visible:not(:disabled):not([readonly])').first().trigger('focus');
        }

        function directRow($target) {
            return $target.closest('.js-opening-balance-line');
        }

        function currentRow($target) {
            const $row = $target.closest('.js-opening-balance-line');

            if ($row.length > 0) {
                $lastFocusedRow = $row;

                return $row;
            }

            if ($lastFocusedRow.length > 0 && $.contains($form.get(0), $lastFocusedRow.get(0))) {
                return $lastFocusedRow;
            }

            return $();
        }

        function addLineAfter($afterRow, values, focusMode) {
            const $row = $(lineTemplate($form, values));

            if ($afterRow && $afterRow.length > 0) {
                $afterRow.after($row);
            } else {
                $form.find('.js-opening-balance-lines tbody').append($row);
            }

            renumberLines($form);
            initSelect2($row.find('.js-opening-balance-account'));
            window.AppNumbers.refresh($row[0]);
            calculateTotals($form);
            if (focusMode === 'account') {
                focusAccountField($row);
            } else {
                focusUsefulField($row);
            }

            return $row;
        }

        $form.off('click.openingBalancesAddLine').on('click.openingBalancesAddLine', '.js-opening-balance-add-line', function () {
            addLineAfter(null, null, 'account');
        });

        $form.off('click.openingBalancesDuplicateLine').on('click.openingBalancesDuplicateLine', '.js-opening-balance-duplicate-line', function () {
            const $currentRow = $(this).closest('.js-opening-balance-line');
            addLineAfter($currentRow, rowValues($currentRow), 'account');
        });

        function clearRow($row) {
            $row.find('.js-opening-balance-account').val(null).trigger('change');
            $row.find('.js-opening-balance-type').val('debit');
            $row.find('.js-opening-balance-amount').val('');
            $row.find('input[name$="[description]"]').val('');
            $row.find('input[type="hidden"][name^="lines["]').val('');
            renumberLines($form);
            calculateTotals($form);
            focusAccountField($row);
        }

        function removeRow($row) {
            const $rows = $form.find('.js-opening-balance-line');
            if ($row.length === 0) {
                return;
            }

            if ($rows.length <= 1) {
                clearRow($row);
                return;
            }

            const nextRow = $row.next('.js-opening-balance-line');
            const previousRow = $row.prev('.js-opening-balance-line');
            $row.remove();
            renumberLines($form);
            calculateTotals($form);
            focusUsefulField(nextRow.length > 0 ? nextRow : previousRow);
        }

        $form.off('click.openingBalancesRemoveLine').on('click.openingBalancesRemoveLine', '.js-opening-balance-remove-line', function () {
            removeRow($(this).closest('.js-opening-balance-line'));
        });

        $form.off('focusin.openingBalancesCurrentRow select2:opening.openingBalancesCurrentRow')
            .on('focusin.openingBalancesCurrentRow select2:opening.openingBalancesCurrentRow', '.js-opening-balance-line :input, .js-opening-balance-account', function () {
                $lastFocusedRow = $(this).closest('.js-opening-balance-line');
            });

        $form.off('keydown.openingBalancesShortcuts').on('keydown.openingBalancesShortcuts', function (event) {
            const $target = $(event.target);
            const $currentRow = currentRow($target);
            const isSelect2Search = $target.hasClass('select2-search__field');
            const isSelect2Open = $('.select2-container--open').length > 0;

            if (isEnter(event) && (isSelect2Search || isSelect2Open)) {
                return;
            }

            if (isAltShortcut(event, ['KeyN'], [78], ['n'])) {
                event.preventDefault();
                event.stopPropagation();
                addLineAfter($currentRow.length > 0 ? $currentRow : null, null, 'account');
                return;
            }

            if (isAltShortcut(event, ['KeyD'], [68], ['d']) && $currentRow.length > 0) {
                event.preventDefault();
                event.stopPropagation();
                addLineAfter($currentRow, rowValues($currentRow), 'account');
                return;
            }

            if (isAltDelete(event) && $currentRow.length > 0) {
                event.preventDefault();
                event.stopPropagation();
                removeRow($currentRow);
                return;
            }

            if (isEnter(event) && !event.altKey && !event.ctrlKey && !event.metaKey && directRow($target).length > 0) {
                event.preventDefault();
                event.stopPropagation();
                focusRelativeField($form, event.shiftKey ? -1 : 1);
            }
        });

        function focusRelativeField($form, direction) {
            const fields = $form.find([
                '.js-opening-balance-account',
                '.js-opening-balance-type',
                '.js-opening-balance-amount',
                '.js-opening-balance-line input[name$="[description]"]',
                '.js-opening-balance-duplicate-line',
                '.js-opening-balance-remove-line'
            ].join(',')).filter(':not(:disabled):not([readonly])').toArray();
            const active = document.activeElement;
            let index = fields.indexOf(active);

            if (index === -1 && active && active.classList.contains('select2-selection')) {
                const select = $(active).closest('.select2-container').prev('select').get(0);
                index = fields.indexOf(select);
            }

            if (direction > 0 && index === fields.length - 1) {
                addLineAfter($form.find('.js-opening-balance-line').last(), null, 'account');
                return;
            }

            const nextIndex = Math.max(0, Math.min(fields.length - 1, (index === -1 ? 0 : index) + direction));
            const nextField = fields[nextIndex] || fields[0];

            if (!nextField) {
                return;
            }

            if ($(nextField).hasClass('js-opening-balance-account') && $.fn.select2) {
                $(nextField).select2('open');
                return;
            }

            nextField.focus();

            if (typeof nextField.select === 'function') {
                nextField.select();
            }
        }

        $form.off('input.openingBalancesTotals change.openingBalancesTotals').on('input.openingBalancesTotals change.openingBalancesTotals', '.js-opening-balance-type, .js-opening-balance-amount', function () {
            calculateTotals($form);
        });

        $form.off('select2:select.openingBalancesAccountNature', '.js-opening-balance-account').on('select2:select.openingBalancesAccountNature', '.js-opening-balance-account', function (event) {
            const data = event.params && event.params.data ? event.params.data : {};
            const nature = String(data.normal_balance || data.account_nature || '').toLowerCase();
            const $type = $(this).closest('.js-opening-balance-line').find('.js-opening-balance-type');

            if (['debit', 'credit'].indexOf(nature) === -1 || $type.length === 0) {
                return;
            }

            $type.val(nature).data('auto-filled-type', nature).trigger('change');
            $(this).find('option:selected').attr('data-normal-balance', nature);
        });

        $form.off('select2:clear.openingBalancesAccountNature change.openingBalancesAccountNature', '.js-opening-balance-account').on('select2:clear.openingBalancesAccountNature change.openingBalancesAccountNature', '.js-opening-balance-account', function () {
            const $account = $(this);

            if ($account.val()) {
                return;
            }

            const $type = $account.closest('.js-opening-balance-line').find('.js-opening-balance-type');
            const autoType = String($type.data('auto-filled-type') || '');

            if (autoType !== '' && $type.val() === autoType) {
                $type.val('').removeData('auto-filled-type').trigger('change');
            }
        });

        $form.off('change.openingBalancesManualType', '.js-opening-balance-type').on('change.openingBalancesManualType', '.js-opening-balance-type', function () {
            const $type = $(this);

            if ($type.val() !== $type.data('auto-filled-type')) {
                $type.removeData('auto-filled-type');
            }
        });

        if (!$form.data('opening-balance-submit-guard')) {
            $form.data('opening-balance-submit-guard', true);
            $form.get(0).addEventListener('submit', function (event) {
                const isBalanced = calculateTotals($form);
                if (!isBalanced) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    $form.find('[data-error-for="lines"]').text(trans('unbalanced', 'Total debit must equal total credit.'));
                }
            }, true);
        }
    }

    function initApproveActions() {
        $(document).off('click.openingBalanceApprove', '.js-approve-opening-balance').on('click.openingBalanceApprove', '.js-approve-opening-balance', function () {
            const $button = $(this);
            const url = $button.data('url');

            if (!url) {
                return;
            }

            const confirmRequest = window.Swal
                ? window.Swal.fire({
                    title: trans('approve_confirm_title', 'Approve opening balance?'),
                    text: trans('approve_confirm_text', 'This will post a system journal entry.'),
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: trans('approve_confirm_yes', 'Approve'),
                    cancelButtonText: trans('cancel', 'Cancel')
                })
                : Promise.resolve({ isConfirmed: window.confirm(trans('approve_confirm_title', 'Approve opening balance?')) });

            confirmRequest.then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $button.prop('disabled', true);
                $.ajax({
                    url: url,
                    method: 'POST',
                    headers: headers()
                }).done(function (response) {
                    if (window.Swal) {
                        window.Swal.fire({ icon: 'success', title: response.message, timer: 1600, showConfirmButton: false });
                    }

                    const table = $('.js-finance-table').DataTable ? $('.js-finance-table').DataTable() : null;
                    if (table) {
                        table.ajax.reload(null, false);
                        return;
                    }

                    window.location.reload();
                }).fail(function (response) {
                    const message = response.responseJSON && response.responseJSON.message ? response.responseJSON.message : trans('unexpected_error', 'Unexpected error.');
                    if (window.Swal) {
                        window.Swal.fire({ icon: 'error', title: message });
                    } else {
                        window.alert(message);
                    }
                }).always(function () {
                    $button.prop('disabled', false);
                });
            });
        });
    }

    $(function () {
        initCurrencyExchangeRate();
        initLineGrid();
        initApproveActions();
    });
})(window.jQuery);
