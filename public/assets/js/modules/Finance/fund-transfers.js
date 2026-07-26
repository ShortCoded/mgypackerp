(function ($, window, document) {
    'use strict';

    const messages = window.fundTransferMessages || {};
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

    function formatAmount(value, scale) {
        const factor = Math.pow(10, scale || 4);

        return (Math.round(numberValue(value) * factor) / factor).toString();
    }

    function holderValue($form, side) {
        const type = $form.find('[name="' + side + '_type"]').val();
        const field = type === 'bank_account' ? side + '_bank_account_doc_num' : side + '_cashbox_doc_num';

        return $form.find('[name="' + field + '"]').val() || '';
    }

    function initSelect2($form) {
        if ($form.length === 0) {
            return;
        }

        if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
            window.AppSelect2Ajax.init($form[0]);
        }
    }

    function selectedOptionData($select, key) {
        const option = $select.find('option:selected').first();

        return option.length ? option.data(key) : null;
    }

    function selectCurrency($form, side, id, text, isMain) {
        const $currency = $form.find('.js-' + side + '-currency');

        if (!id || !text || $currency.length === 0) {
            return;
        }

        if ($currency.find('option[value="' + id + '"]').length === 0) {
            $currency.append(new Option(text, id, true, true));
        }

        $currency.val(id).trigger('change');
        $currency.find('option[value="' + id + '"]').attr('data-is-main', isMain ? '1' : '0').data('is-main', isMain ? 1 : 0);
    }

    function updateHolderVisibility($form, side) {
        const type = $form.find('[name="' + side + '_type"]').val();

        $form.find('.js-fund-transfer-holder[data-side="' + side + '"]').each(function () {
            const active = String($(this).data('holder-type')) === type;

            $(this).toggleClass('d-none', !active);
            $(this).find('select').prop('disabled', !active);
        });

        updateHolderDocInput($form, side);
    }

    function updateHolderDocInput($form, side) {
        $form.find('#' + side + '_holder_doc_num').val(holderValue($form, side));
    }

    function currenciesMatch($form) {
        const source = String($form.find('.js-source-currency').val() || '');
        const target = String($form.find('.js-target-currency').val() || '');

        return source !== '' && source === target;
    }

    function updateAmounts($form) {
        const sourceAmount = numberValue($form.find('.js-fund-transfer-source-amount').val());
        const $rate = $form.find('.js-fund-transfer-exchange-rate');
        const $target = $form.find('.js-fund-transfer-target-amount');

        $target.prop('readonly', true);

        if (currenciesMatch($form)) {
            $rate.val('1').prop('readonly', true);
            $target.val(sourceAmount > 0 ? formatAmount(sourceAmount) : '');
            window.AppNumbers.refresh($rate[0]);
            window.AppNumbers.refresh($target[0]);
            return;
        }

        $rate.prop('readonly', false);

        const rate = numberValue($rate.val());
        $target.val(sourceAmount > 0 && rate > 0 ? formatAmount(sourceAmount * rate) : '');
        window.AppNumbers.refresh($rate[0]);
        window.AppNumbers.refresh($target[0]);
    }

    function initForm() {
        const $form = $('.js-fund-transfer-form').first();

        if ($form.length === 0) {
            return;
        }

        initSelect2($form);
        updateHolderVisibility($form, 'source');
        updateHolderVisibility($form, 'target');
        updateHolderDocInput($form, 'source');
        updateHolderDocInput($form, 'target');
        updateAmounts($form);

        $form.on('change', '.js-fund-transfer-holder-type', function () {
            const side = String($(this).data('side') || '');

            $form.find('.js-fund-transfer-holder-select[data-side="' + side + '"]').val(null).trigger('change');
            updateHolderVisibility($form, side);
            updateHolderDocInput($form, side);
            $form.find('.js-' + side + '-currency').val(null).trigger('change');
            updateAmounts($form);
        });

        $form.on('select2:select', '.js-fund-transfer-holder-select', function (event) {
            const $select = $(this);
            const side = String($select.data('side') || '');
            const data = event.params && event.params.data ? event.params.data : {};

            updateHolderDocInput($form, side);

            if ($select.data('holder-type') === 'bank_account' && data.currency_doc_num && data.currency_text) {
                selectCurrency($form, side, data.currency_doc_num, data.currency_text, data.currency_is_main);
            } else {
                $form.find('.js-' + side + '-currency').val(null).trigger('change');
            }

            updateAmounts($form);
        });

        $form.on('select2:clear change', '.js-fund-transfer-holder-select', function () {
            const side = String($(this).data('side') || '');

            updateHolderDocInput($form, side);
            if (!$(this).val()) {
                $form.find('.js-' + side + '-currency').val(null).trigger('change');
            }
            updateAmounts($form);
        });

        $form.on('select2:select change', '.js-fund-transfer-currency', function () {
            updateAmounts($form);
        });

        $form.on('input', '.js-fund-transfer-source-amount, .js-fund-transfer-exchange-rate', function () {
            updateAmounts($form);
        });

        $(document).off('finance:form-reset.fundTransfers').on('finance:form-reset.fundTransfers', function (event, resetForm, resource) {
            if (resource !== 'fund_transfers') {
                return;
            }

            resetForm.find('[name="source_type"]').val('cashbox');
            resetForm.find('[name="target_type"]').val('bank_account');
            resetForm.find('.js-fund-transfer-holder-select').val(null).trigger('change');
            initSelect2(resetForm);
            updateHolderVisibility(resetForm, 'source');
            updateHolderVisibility(resetForm, 'target');
            updateHolderDocInput(resetForm, 'source');
            updateHolderDocInput(resetForm, 'target');
            updateAmounts(resetForm);
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
        $(document).off('click.fundTransferApprove', '.js-fund-transfer-approve').on('click.fundTransferApprove', '.js-fund-transfer-approve', function () {
            const url = $(this).data('url');

            confirmAction({
                title: msg('approve_confirm_title'),
                text: msg('approve_confirm_text'),
                confirmButtonText: msg('approve_confirm_yes')
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

        $(document).off('click.fundTransferCancel', '.js-fund-transfer-cancel').on('click.fundTransferCancel', '.js-fund-transfer-cancel', function () {
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

        $(document).off('click.fundTransferPrint', '.js-fund-transfer-print').on('click.fundTransferPrint', '.js-fund-transfer-print', function () {
            $.ajax({ url: $(this).data('url'), method: 'GET', headers: headers() }).fail(function (response) {
                toast('info', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpected_error'));
            });
        });
    }

    initForm();
    initActions();
})(jQuery, window, document);
