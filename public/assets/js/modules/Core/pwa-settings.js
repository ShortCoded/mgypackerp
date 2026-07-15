(function ($, window, document) {
    'use strict';

    const formSelector = '.js-pwa-settings-form';

    function escapeSelector(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }

        return String(value || '').replace(/"/g, '\\"');
    }

    function clearFieldError($field) {
        const name = $field.attr('name');
        const $form = $field.closest('form');

        if (!name || !$form.length) {
            return;
        }

        $field.removeClass('is-invalid');
        $form.find('[data-error-for="' + escapeSelector(name) + '"]').text('');
    }

    function iconElements($field) {
        return {
            image: $field.find('.js-pwa-icon-preview').first(),
            placeholder: $field.find('.js-pwa-icon-placeholder').first(),
            fileName: $field.find('.js-pwa-icon-file-name').first(),
            buttonLabel: $field.find('.js-pwa-icon-button-label').first()
        };
    }

    function renderIconPreview($field, options) {
        const values = options || {};
        const elements = iconElements($field);
        const src = String(values.src || '').trim();
        const label = String(values.label || '').trim();

        if (src !== '') {
            elements.image.attr('src', src).removeClass('d-none');
            elements.placeholder.addClass('d-none');
        } else {
            elements.image.attr('src', '').addClass('d-none');
            elements.placeholder.removeClass('d-none');
        }

        elements.fileName.text(label || $field.data('no-image-label') || '');
        elements.fileName.attr('title', label || $field.data('no-image-label') || '');

        if (elements.buttonLabel.length && $field.data('replace-label')) {
            elements.buttonLabel.text($field.data('replace-label'));
        }
    }

    function previewUrl(file, data) {
        file = file || {};
        data = data || {};

        return String(file.thumbnail_url || data.thumbnail_url || file.url || data.url || '').trim();
    }

    function fileName(file, data) {
        file = file || {};
        data = data || {};

        return String(file.name || data.name || file.original_name || data.original_name || '').trim();
    }

    function handleIconSelection(payload) {
        const data = payload || {};
        const file = data.file || data;
        const config = data.config || {};
        const publicId = file ? (file.public_id || data.public_id || '') : '';
        const $hidden = config.targetInput ? $(config.targetInput).first() : $();
        const $field = config.uploader ? $(config.uploader).first() : $hidden.closest('.js-pwa-icon-card');

        if (config.collection !== 'pwa_icon' || !file || publicId === '' || !$hidden.length || !$field.length) {
            return;
        }

        $hidden.val(publicId).trigger('change');
        renderIconPreview($field, {
            src: previewUrl(file, data),
            label: fileName(file, data) || $field.data('selected-label') || ''
        });
        clearFieldError($hidden);
    }

    function handleIconDeletion(payload) {
        const data = payload || {};
        const config = data.config || {};
        const $hidden = config.targetInput ? $(config.targetInput).first() : $();
        const $field = config.uploader ? $(config.uploader).first() : $hidden.closest('.js-pwa-icon-card');

        if (config.collection !== 'pwa_icon' || !data.was_selected || !$hidden.length || !$field.length) {
            return;
        }

        $hidden.val('').trigger('change');
        renderIconPreview($field, {
            src: String($field.data('current-url') || '').trim(),
            label: String($field.data('current-label') || '').trim()
        });
        clearFieldError($hidden);
    }

    function init(root) {
        const $root = $(root || document);
        const $form = $root.find(formSelector).first();

        if (!$form.length || $form.attr('data-pwa-settings-initialized') === 'true') {
            return;
        }

        $form.attr('data-pwa-settings-initialized', 'true');

        $form.find('input, textarea, select').on('input change', function () {
            clearFieldError($(this));
        });
    }

    $(document)
        .off('file-picker:selected.corePwaIconPicker', '.js-pwa-icon-picker-trigger')
        .on('file-picker:selected.corePwaIconPicker', '.js-pwa-icon-picker-trigger', function (event, payload) {
            handleIconSelection(payload);
        })
        .off('file-picker:deleted.corePwaIconPicker', '.js-pwa-icon-picker-trigger')
        .on('file-picker:deleted.corePwaIconPicker', '.js-pwa-icon-picker-trigger', function (event, payload) {
            handleIconDeletion(payload);
        });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }
})(jQuery, window, document);
