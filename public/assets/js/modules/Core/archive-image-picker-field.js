(function ($, window) {
    'use strict';

    function elements($field) {
        return {
            image: $field.find('.js-archive-image-preview').first(),
            placeholder: $field.find('.js-archive-image-placeholder').first(),
            fileName: $field.find('.js-archive-image-file-name').first(),
            removeButton: $field.find('.js-archive-image-remove').first()
        };
    }

    function clearPreview($field) {
        const fieldElements = elements($field);

        fieldElements.image.off('.archiveImagePicker').attr('src', '').addClass('d-none');
        fieldElements.placeholder.removeClass('d-none');
        fieldElements.fileName.text(String($field.data('empty-label') || ''));
        fieldElements.removeButton.addClass('d-none');
    }

    function renderPreview($field, file) {
        const fieldElements = elements($field);
        const url = String(file.thumbnail_url || file.url || '');
        const fileName = String(file.name || file.original_name || $field.data('existing-label') || '');
        const image = fieldElements.image.get(0);

        if (!image || url === '') {
            clearPreview($field);
            return;
        }

        fieldElements.image
            .off('.archiveImagePicker')
            .addClass('d-none')
            .one('load.archiveImagePicker', function () {
                $(this).removeClass('d-none');
                fieldElements.placeholder.addClass('d-none');
            })
            .one('error.archiveImagePicker', function () {
                clearPreview($field);
            })
            .attr('alt', fileName)
            .attr('src', url);
        fieldElements.placeholder.addClass('d-none');
        fieldElements.fileName.text(fileName);
        fieldElements.removeButton.removeClass('d-none');

        if (image.complete && image.naturalWidth > 0) {
            fieldElements.image.triggerHandler('load.archiveImagePicker');
        } else if (image.complete && image.naturalWidth === 0) {
            fieldElements.image.triggerHandler('error.archiveImagePicker');
        }
    }

    function hiddenInput(config, $trigger) {
        return config.targetInput ? $(config.targetInput).first() : $trigger.closest('form').find('.js-archive-image-picker-input').first();
    }

    function pickerField(config, $trigger) {
        return config.uploader ? $(config.uploader).first() : $trigger.closest('.js-archive-image-picker-field');
    }

    function clearSelection($field) {
        const fieldName = String($field.data('field-name') || '');
        const $form = $field.closest('form');

        $form.find('[name="' + fieldName + '"]').val('').trigger('change').removeClass('is-invalid');
        $form.find('[data-error-for="' + fieldName + '"]').text('');
        clearPreview($field);
    }

    function init() {
        $('.js-archive-image-picker-field').each(function () {
            const $field = $(this);
            const url = String($field.data('current-url') || '').trim();

            if (url === '') {
                clearPreview($field);
                return;
            }

            renderPreview($field, {
                url: url,
                name: String($field.data('existing-label') || '')
            });
        });

        $(document)
            .off('file-picker:selected.archiveImagePicker', '.js-archive-image-picker-trigger')
            .on('file-picker:selected.archiveImagePicker', '.js-archive-image-picker-trigger', function (event, payload) {
                const data = payload || {};
                const file = data.file || data;
                const config = data.config || {};
                const publicId = String(file.public_id || data.public_id || '').trim();
                const $trigger = $(this);
                const $hidden = hiddenInput(config, $trigger);
                const $field = pickerField(config, $trigger);

                if (publicId === '' || $hidden.length === 0 || $field.length === 0) {
                    return;
                }

                $hidden.val(publicId).trigger('change').removeClass('is-invalid');
                $hidden.closest('form').find('[data-error-for="' + String($field.data('field-name') || '') + '"]').text('');
                renderPreview($field, file);
            })
            .off('file-picker:deleted.archiveImagePicker', '.js-archive-image-picker-trigger')
            .on('file-picker:deleted.archiveImagePicker', '.js-archive-image-picker-trigger', function (event, payload) {
                const data = payload || {};

                if (!data.was_selected) {
                    return;
                }

                clearSelection(pickerField(data.config || {}, $(this)));
            })
            .off('click.archiveImagePicker', '.js-archive-image-picker-field .js-archive-image-remove')
            .on('click.archiveImagePicker', '.js-archive-image-picker-field .js-archive-image-remove', function (event) {
                event.preventDefault();
                event.stopPropagation();
                clearSelection($(this).closest('.js-archive-image-picker-field'));
            });
    }

    window.AppArchiveImagePicker = {
        init: init,
        reset: function ($scope) {
            $scope.find('.js-archive-image-picker-field').each(function () {
                clearSelection($(this));
            });
        }
    };

    init();
})(jQuery, window);
