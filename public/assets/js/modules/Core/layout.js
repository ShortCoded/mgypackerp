(function ($) {
    'use strict';

    function authHeaders() {
        return {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || '',
            Accept: 'application/json'
        };
    }

    function applyDirection(direction) {
        var isRTL = direction === 'rtl';

        localStorage.setItem('isRTL', JSON.stringify(isRTL));
        $('html').attr('dir', isRTL ? 'rtl' : 'ltr');

        $('#style-default, #user-style-default').each(function () {
            this.disabled = isRTL;
        });

        $('#style-rtl, #user-style-rtl').each(function () {
            this.disabled = !isRTL;
        });
    }

    function initLanguageSelect() {
        var $select = $('.js-app-language-select');

        if ($select.length === 0) {
            return;
        }

        if ($.fn.select2) {
            $select.select2({
                theme: 'bootstrap-5',
                minimumResultsForSearch: Infinity,
                width: '100%',
                dropdownParent: $('#settings-offcanvas')
            });
        }

        $select.on('change', function () {
            var $currentSelect = $(this);
            var $option = $currentSelect.find(':selected');
            var direction = $option.data('dir') || 'ltr';
            var url = ($currentSelect.data('language-switch-url') || '').replace('__LOCALE__', $currentSelect.val());

            //applyDirection(direction);

            $.ajax({
                url: url,
                method: 'GET',
                headers: authHeaders()
            }).done(function () {
                window.location.reload();
            });
        });
    }

    $(initLanguageSelect);
})(jQuery);
