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

    function positionNestedTopMenu(branch) {
        if (!branch || window.innerWidth < 992) {
            return;
        }

        var submenu = branch.querySelector(':scope > .erp-top-nav-submenu');

        if (!submenu) {
            return;
        }

        branch.classList.remove('erp-top-nav-branch-flipped');

        window.requestAnimationFrame(function () {
            var rect = submenu.getBoundingClientRect();
            var isRTL = document.documentElement.getAttribute('dir') === 'rtl';
            var crossesViewport = isRTL ? rect.left < 8 : rect.right > window.innerWidth - 8;

            branch.classList.toggle('erp-top-nav-branch-flipped', crossesViewport);
        });
    }

    function initNestedTopMenuPositioning() {
        var navigation = document.querySelector('[data-top-nav-dropdowns]');

        if (!navigation) {
            return;
        }

        navigation.addEventListener('mouseover', function (event) {
            var branch = event.target.closest('.erp-top-nav-branch');

            if (branch) {
                window.setTimeout(function () { positionNestedTopMenu(branch); }, 0);
            }
        });

        navigation.addEventListener('focusin', function (event) {
            var branch = event.target.closest('.erp-top-nav-branch');

            if (branch) {
                window.setTimeout(function () { positionNestedTopMenu(branch); }, 0);
            }
        });

        navigation.addEventListener('shown.bs.dropdown', function (event) {
            positionNestedTopMenu(event.target.closest('.erp-top-nav-branch'));
        });

        window.addEventListener('resize', function () {
            navigation.querySelectorAll('.erp-top-nav-branch').forEach(positionNestedTopMenu);
        });
    }

    $(function () {
        initLanguageSelect();
        initNestedTopMenuPositioning();
    });
})(jQuery);
