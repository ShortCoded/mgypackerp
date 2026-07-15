(function ($, window) {
    'use strict';

    const messages = window.hrInlineSelect2Messages || {};

    function csrfToken() {
        return $('meta[name="csrf-token"]').attr('content');
    }

    function headers() {
        return {
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json'
        };
    }

    function message(key) {
        return messages[key] || '';
    }

    function responseMessage(xhr) {
        return xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : message('unexpectedError');
    }

    function showToast(icon, title) {
        if (!window.Swal || !title) {
            return;
        }

        const toast = Swal.mixin({
            toast: true,
            position: document.documentElement.getAttribute('dir') === 'rtl' ? 'top-left' : 'top-right',
            backdrop: false,
            heightAuto: false,
            timer: 5000,
            timerProgressBar: true,
            showCloseButton: true,
            showConfirmButton: false,
            customClass: {
                container: 'erp-swal-toast-container',
                popup: 'erp-swal-toast-popup'
            },
            didOpen: function (element) {
                element.addEventListener('mouseenter', Swal.stopTimer);
                element.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });

        if (Swal.isVisible() && !$('.swal2-popup').hasClass('swal2-toast')) {
            Swal.close();
        }

        toast.fire({ icon: icon, title: title });
    }

    function showAlert($container, text, type) {
        if (!$container.length || !text) {
            return;
        }

        $container.html('<div class="alert alert-' + (type || 'danger') + ' mb-3">' + $('<div>').text(text).html() + '</div>');
    }

    function clearValidation($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $form.find('[data-form-alert]').empty();
    }

    function renderValidation($form, errors) {
        $.each(errors || {}, function (field, fieldMessages) {
            var normalizedField = String(field || '').replace('[]', '');
            var $field = $form.find('[name="' + normalizedField + '"], [name="' + normalizedField + '[]"]');

            $field.addClass('is-invalid');
            $form.find('[data-error-for="' + normalizedField + '"]').text((fieldMessages || [])[0] || '');
        });
    }

    function currentModal() {
        return $('#hr-inline-lookup-modal');
    }

    function parseInlineMergeFields($button) {
        var raw = $button.attr('data-inline-merge');

        if (!raw) {
            return [];
        }

        try {
            var parsed = JSON.parse(raw);

            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function appendInlineMergeToBody(body, mergeFields) {
        var result = body;

        (mergeFields || []).forEach(function (item) {
            var sel = String(item.selector || '');
            var name = String(item.name || '');

            if (!sel || !name) {
                return;
            }

            var $src = $(sel);
            var v = $src.val();

            if (v !== null && v !== undefined && String(v).trim() !== '') {
                result += '&' + encodeURIComponent(name) + '=' + encodeURIComponent(String(v));
            }
        });

        return result;
    }

    function showInlineLookupModal($button) {
        var $modal = currentModal();
        var $form = $('#hr-inline-lookup-form');
        var label = String($button.data('label') || '');
        var targetSelect = String($button.data('target-select') || '');

        if (!$modal.length || !$form.length || !$button.data('url') || targetSelect === '') {
            return;
        }

        clearValidation($form);
        $form.attr('action', $button.data('url'));
        $form.find('[name="target_select"]').val(targetSelect);
        $form.data('hrInlineMergeFields', parseInlineMergeFields($button));
        $form.find('[name="name"], [name="notes"]').val('');
        var titleTemplate = message('inlineLookupTitle');
        $modal.find('.js-inline-lookup-title').text(titleTemplate.replace(':lookup', label));

        var modal = window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance($modal[0])
            : null;

        if (modal) {
            modal.show();
            return;
        }

        if ($modal.modal) {
            $modal.modal('show');
        }
    }

    function selectInlineLookupOption(targetSelector, option) {
        var $select = $(targetSelector);

        if (!$select.length || !option || option.id === undefined || option.text === undefined) {
            return;
        }

        var value = String(option.id);
        var exists = $select.find('option').filter(function () {
            return String(this.value) === value;
        }).length > 0;

        if (!exists) {
            $select.append(new Option(String(option.text), value, true, true));
        }

        $select.val(value).trigger('change').trigger('change.select2');
    }

    $(document)
        .off('click.hrInlineSelect2', '.js-inline-lookup-create')
        .on('click.hrInlineSelect2', '.js-inline-lookup-create', function () {
            showInlineLookupModal($(this));
        })
        .off('submit.hrInlineSelect2', '#hr-inline-lookup-form')
        .on('submit.hrInlineSelect2', '#hr-inline-lookup-form', function (event) {
            event.preventDefault();

            var $form = $(this);
            var targetSelector = String($form.find('[name="target_select"]').val() || '');

            clearValidation($form);

            var mergeFields = $form.data('hrInlineMergeFields') || [];
            var body = appendInlineMergeToBody($form.serialize(), mergeFields);

            $.ajax({
                url: $form.attr('action'),
                type: 'POST',
                headers: headers(),
                data: body,
                contentType: 'application/x-www-form-urlencoded; charset=UTF-8'
            }).done(function (response) {
                var option = response && response.data ? response.data.option : null;

                selectInlineLookupOption(targetSelector, option);
                showToast('success', response && response.message ? response.message : message('inlineLookupCreated'));

                var $modal = currentModal();
                var modal = window.bootstrap && window.bootstrap.Modal && $modal.length
                    ? window.bootstrap.Modal.getInstance($modal[0])
                    : null;

                if (modal) {
                    modal.hide();
                    return;
                }

                if ($modal.modal) {
                    $modal.modal('hide');
                }
            }).fail(function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    renderValidation($form, xhr.responseJSON.errors);
                    var summaryTitle = message('validationSummaryTitle') || message('validationFailed');
                    showAlert($form.find('[data-form-alert]'), summaryTitle, 'danger');
                    return;
                }

                showAlert($form.find('[data-form-alert]'), responseMessage(xhr), 'danger');
            });
        })
        .off('input.hrInlineSelect2Validation change.hrInlineSelect2Validation', '#hr-inline-lookup-form input, #hr-inline-lookup-form textarea')
        .on('input.hrInlineSelect2Validation change.hrInlineSelect2Validation', '#hr-inline-lookup-form input, #hr-inline-lookup-form textarea', function () {
            var $field = $(this);
            var name = $field.attr('name');

            if (!name) {
                return;
            }

            $field.removeClass('is-invalid');
            $('#hr-inline-lookup-form').find('[data-error-for="' + name + '"]').text('');
        });
})(jQuery, window);
