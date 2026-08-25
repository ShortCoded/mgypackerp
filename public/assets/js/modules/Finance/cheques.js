(function ($, window, document) {
    'use strict';

    const messages = window.chequeMessages || {};
    const csrfToken = $('meta[name="csrf-token"]').attr('content');

    function msg(key) {
        return messages[key] || key;
    }

    function headers() {
        return {
            'X-CSRF-TOKEN': csrfToken,
            Accept: 'application/json'
        };
    }

    function toast(icon, title) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
        }
    }

    function confirmAction(options) {
        if (!window.Swal) {
            return $.Deferred().resolve({ isConfirmed: false }).promise();
        }

        return Swal.fire({
            icon: 'warning',
            title: options.title,
            text: options.text,
            input: options.input || undefined,
            inputPlaceholder: options.inputPlaceholder || undefined,
            inputValidator: options.inputValidator || undefined,
            showCloseButton: true,
            showCancelButton: true,
            focusCancel: true,
            confirmButtonText: options.confirmButtonText,
            cancelButtonText: msg('cancel'),
            confirmButtonColor: options.confirmButtonColor || '#00a65a',
            cancelButtonColor: '#748194'
        });
    }

    function numberValue(value) {
        return window.AppNumbers.number(value, 0);
    }

    function formatAmount(value) {
        return window.AppNumbers.format((Math.round(numberValue(value) * 10000) / 10000).toString());
    }

    function initSelect2($form) {
        if ($form.length === 0) {
            return;
        }

        if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
            window.AppSelect2Ajax.init($form[0]);
        }
    }

    function initAccountSelects($scope) {
        initSelect2($scope);
    }

    function escapeHtml(value) {
        return $('<div></div>').text(String(value || '')).html();
    }

    function selectedOptionData($select, key) {
        const option = $select.find('option:selected').first();

        return option.length ? option.data(key) : null;
    }

    function selectCurrency($form, id, text, isMain) {
        const $currency = $form.find('.js-cheque-currency');

        if (!id || !text || $currency.length === 0) {
            return;
        }

        if ($currency.find('option[value="' + id + '"]').length === 0) {
            $currency.append(new Option(text, id, true, true));
        }

        $currency.val(id).trigger('change');
        $currency.find('option[value="' + id + '"]').attr('data-is-main', isMain ? '1' : '0').data('is-main', isMain ? 1 : 0);
        updateExchangeRate($form);
    }

    function updateExchangeRate($form) {
        const $currency = $form.find('.js-cheque-currency');
        const isMain = String(selectedOptionData($currency, 'is-main') || '') === '1' || $currency.val() === String($form.data('main-currency-doc-num') || '');
        const $rate = $form.find('.js-cheque-exchange-rate');

        if (isMain) {
            $rate.val('1').prop('readonly', true);
            return;
        }

        $rate.prop('readonly', false);
    }

    function updateTotals($form) {
        const amount = numberValue($form.find('.js-cheque-amount').val());
        let total = 0;

        $form.find('.js-cheque-line-amount').each(function () {
            total += numberValue($(this).val());
        });

        const remaining = amount - total;

        $form.find('.js-cheque-total-distributed').text(formatAmount(total));
        $form.find('.js-cheque-remaining').text(formatAmount(remaining));

        if (remaining < -0.00001) {
            toast('warning', msg('distribution_exceeds_amount'));
        }
    }

    function partySelectForType($form, type) {
        if (type === 'customer') {
            return $form.find('.js-cheque-customer-party');
        }

        if (type === 'supplier') {
            return $form.find('.js-cheque-supplier-party');
        }

        return $();
    }

    function clearPartySelects($form) {
        $form.find('.js-cheque-party-select').each(function () {
            $(this).val(null).trigger('change');
        });
    }

    function updatePartyFields($form) {
        const type = String($form.find('.js-cheque-party-type').val() || '');
        const $customer = $form.find('.js-cheque-party-customer');
        const $supplier = $form.find('.js-cheque-party-supplier');
        const $name = $form.find('.js-cheque-party-name');
        const $partyName = $form.find('#party_name');
        const $activeParty = partySelectForType($form, type);
        const hasSelectedParty = $activeParty.length > 0 && String($activeParty.val() || '') !== '';
        const showName = type === '' || type === 'other' || (['customer', 'supplier'].indexOf(type) !== -1 && !hasSelectedParty);

        $customer.toggleClass('d-none', type !== 'customer');
        $supplier.toggleClass('d-none', type !== 'supplier');
        $customer.find('select').prop('disabled', type !== 'customer');
        $supplier.find('select').prop('disabled', type !== 'supplier');

        $name.toggleClass('d-none', !showName);
        $partyName.prop('disabled', !showName).prop('required', showName);
    }

    function lineTemplate($form, index) {
        const accountUrl = escapeHtml($form.data('account-url') || '');
        const accountPlaceholder = escapeHtml(msg('select_account'));
        const duplicateTitle = escapeHtml(msg('duplicate_line_title'));
        const deleteTitle = escapeHtml(msg('delete_line_title'));

        return '' +
            '<tr class="js-cheque-line" data-index="' + index + '">' +
            '<td><select class="form-select js-select2-ajax js-cheque-account" name="lines[' + index + '][account_doc_num]" data-url="' + accountUrl + '" data-placeholder="' + accountPlaceholder + '" data-allow-clear="true"></select><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.account_doc_num"></div></td>' +
            '<td><input class="form-control text-end js-cheque-line-amount" name="lines[' + index + '][amount]" type="text" inputmode="decimal" min="0.0001" step="0.0001" dir="ltr" data-numeric-input data-numeric-scale="4" data-numeric-min="0.0001"><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.amount"></div></td>' +
            '<td><input class="form-control" name="lines[' + index + '][description]"><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.description"></div></td>' +
            '<td><input class="form-control" name="lines[' + index + '][notes]"><div class="invalid-feedback d-block" data-error-for="lines.' + index + '.notes"></div></td>' +
            '<td class="text-center"><button class="btn btn-link text-600 p-0 me-2 js-cheque-duplicate-line" type="button" title="' + duplicateTitle + '" data-bs-title="' + duplicateTitle + '"><span class="fas fa-copy"></span></button><button class="btn btn-link text-danger p-0 js-cheque-remove-line" type="button" title="' + deleteTitle + '" data-bs-title="' + deleteTitle + '"><span class="fas fa-trash-alt"></span></button></td>' +
            '</tr>';
    }

    function renumberLines($form) {
        $form.find('.js-cheque-line').each(function (index) {
            $(this).attr('data-index', index);
            $(this).find('[name]').each(function () {
                const name = String($(this).attr('name') || '');

                $(this).attr('name', name.replace(/lines\[\d+\]/, 'lines[' + index + ']'));
            });
            $(this).find('[data-error-for]').each(function () {
                const key = String($(this).attr('data-error-for') || '');

                $(this).attr('data-error-for', key.replace(/lines\.\d+\./, 'lines.' + index + '.'));
            });
        });
    }

    function initLines($form) {
        $form.off('click.chequeAddLine').on('click.chequeAddLine', '.js-cheque-add-line', function () {
            const index = $form.find('.js-cheque-line').length;
            const $row = $(lineTemplate($form, index));

            $form.find('.js-cheque-lines tbody').append($row);
            initAccountSelects($row);
            window.AppNumbers.refresh($row[0]);
        });

        $form.off('click.chequeRemoveLine').on('click.chequeRemoveLine', '.js-cheque-remove-line', function () {
            if ($form.find('.js-cheque-line').length > 1) {
                $(this).closest('.js-cheque-line').remove();
                renumberLines($form);
            } else {
                $(this).closest('.js-cheque-line').find('input').val('');
                $(this).closest('.js-cheque-line').find('select').val(null).trigger('change');
            }

            updateTotals($form);
        });

        $form.off('click.chequeDuplicateLine').on('click.chequeDuplicateLine', '.js-cheque-duplicate-line', function () {
            const $source = $(this).closest('.js-cheque-line');
            const index = $form.find('.js-cheque-line').length;
            const $row = $(lineTemplate($form, index));
            const $sourceAccount = $source.find('.js-cheque-account option:selected');

            $form.find('.js-cheque-lines tbody').append($row);
            if ($sourceAccount.length && $sourceAccount.val()) {
                $row.find('.js-cheque-account').append(new Option($sourceAccount.text(), $sourceAccount.val(), true, true));
            }
            $row.find('.js-cheque-line-amount').val($source.find('.js-cheque-line-amount').val());
            $row.find('[name$="[description]"]').val($source.find('[name$="[description]"]').val());
            $row.find('[name$="[notes]"]').val($source.find('[name$="[notes]"]').val());
            initAccountSelects($row);
            window.AppNumbers.refresh($row[0]);
            updateTotals($form);
        });

        $form.off('input.chequeTotals').on('input.chequeTotals', '.js-cheque-amount, .js-cheque-line-amount', function () {
            updateTotals($form);
        });
    }

    function initForm() {
        const $form = $('.js-cheque-form').first();

        if ($form.length === 0) {
            return;
        }

        initSelect2($form);
        initLines($form);
        updatePartyFields($form);
        updateExchangeRate($form);
        updateTotals($form);

        $form.on('select2:select', '.js-cheque-bank-account', function (event) {
            const data = event.params && event.params.data ? event.params.data : {};

            if (data.currency_doc_num && data.currency_text) {
                selectCurrency($form, data.currency_doc_num, data.currency_text, data.currency_is_main);
            } else {
                $form.find('.js-cheque-currency').val(null).trigger('change');
            }
        });

        $form.on('select2:clear change', '.js-cheque-bank-account', function () {
            if (!$(this).val()) {
                $form.find('.js-cheque-currency').val(null).trigger('change');
            }
        });

        $form.on('select2:select change', '.js-cheque-currency', function () {
            updateExchangeRate($form);
        });

        $form.on('change', '.js-cheque-party-type', function () {
            clearPartySelects($form);
            $form.find('#party_name').val('');
            updatePartyFields($form);
        });

        $form.on('select2:select', '.js-cheque-party-select', function (event) {
            const data = event.params && event.params.data ? event.params.data : {};

            if (data.name) {
                $form.find('#party_name').val(data.name);
            }

            updatePartyFields($form);
        });

        $form.on('select2:clear change', '.js-cheque-party-select', function () {
            const type = String($form.find('.js-cheque-party-type').val() || '');
            const $activeParty = partySelectForType($form, type);

            if ($activeParty.length === 0 || !String($activeParty.val() || '')) {
                $form.find('#party_name').val('');
            }

            updatePartyFields($form);
        });

        $(document).off('finance:form-reset.cheques').on('finance:form-reset.cheques', function (event, resetForm, resource) {
            if (resource !== 'cheques') {
                return;
            }

            resetForm.find('.js-cheque-type').val('received');
            resetForm.find('.js-cheque-party-type').val('');
            clearPartySelects(resetForm);
            resetForm.find('#party_name').val('');
            resetForm.find('.js-cheque-lines tbody').html(lineTemplate(resetForm, 0));
            initSelect2(resetForm);
            window.AppNumbers.refresh(resetForm[0]);
            updatePartyFields(resetForm);
            updateTotals(resetForm);
        });
    }

    function postAction(url, data) {
        return $.ajax({
            url: url,
            method: 'POST',
            data: data || {},
            headers: headers()
        });
    }

    function initActions() {
        $(document).off('click.chequeStatus', '.js-cheque-status-action').on('click.chequeStatus', '.js-cheque-status-action', function () {
            const url = $(this).data('url');

            confirmAction({
                title: msg('status_confirm_title'),
                text: msg('status_confirm_text'),
                confirmButtonText: msg('status_confirm_yes')
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                postAction(url).done(function (response) {
                    toast('success', response.message);
                    window.location.reload();
                }).fail(function (response) {
                    toast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpected_error'));
                });
            });
        });

        $(document).off('click.chequeCancel', '.js-cheque-cancel').on('click.chequeCancel', '.js-cheque-cancel', function () {
            const url = $(this).data('url');

            confirmAction({
                title: msg('cancel_confirm_title'),
                text: msg('cancel_confirm_text'),
                input: 'textarea',
                inputPlaceholder: msg('cancel_reason_placeholder'),
                inputValidator: function (value) {
                    return value && value.trim() !== '' ? null : msg('cancel_reason_placeholder');
                },
                confirmButtonText: msg('cancel_confirm_yes'),
                confirmButtonColor: '#f5803e'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                postAction(url, { cancel_reason: result.value }).done(function (response) {
                    toast('success', response.message);
                    window.location.reload();
                }).fail(function (response) {
                    toast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpected_error'));
                });
            });
        });

        $(document).off('click.chequeReverseClearing', '.js-cheque-reverse-clearing').on('click.chequeReverseClearing', '.js-cheque-reverse-clearing', function () {
            const url = $(this).data('url');

            confirmAction({
                title: msg('reverse_clearing_confirm_title'),
                text: msg('reverse_clearing_confirm_text'),
                input: 'textarea',
                inputPlaceholder: msg('reverse_clearing_reason_placeholder'),
                inputValidator: function (value) {
                    return value && value.trim() !== '' ? null : msg('reverse_clearing_reason_placeholder');
                },
                confirmButtonText: msg('reverse_clearing_confirm_yes'),
                confirmButtonColor: '#f5803e'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                postAction(url, { reason: result.value }).done(function (response) {
                    toast('success', response.message);
                    window.location.reload();
                }).fail(function (response) {
                    toast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpected_error'));
                });
            });
        });

        $(document).off('click.chequePrint', '.js-cheque-print').on('click.chequePrint', '.js-cheque-print', function () {
            $.ajax({ url: $(this).data('url'), method: 'GET', headers: headers() }).fail(function (response) {
                toast('info', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpected_error'));
            });
        });
    }

    initForm();
    initActions();
})(jQuery, window, document);
