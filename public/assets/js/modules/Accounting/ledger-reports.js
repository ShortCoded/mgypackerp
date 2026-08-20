(function ($, document) {
    'use strict';

    $(function () {
        if (!$.fn.select2) {
            return;
        }

        $('.js-ledger-select').each(function () {
            const $select = $(this);
            if ($select.data('select2')) {
                return;
            }

            $select.select2({
                theme: 'bootstrap-5',
                width: '100%',
                dir: document.documentElement.getAttribute('dir') || 'ltr',
                placeholder: $select.data('placeholder') || '',
                allowClear: $select.data('allow-clear') === true || String($select.data('allow-clear')) === 'true',
                ajax: {
                    url: $select.data('url'),
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return { q: params.term || '', page: params.page || 1 };
                    }
                }
            });
        });
    });
})(jQuery, document);
