(function ($, window, document) {
    'use strict';

    function optionMatches($option, attribute, value) {
        return value === '' || String($option.data(attribute) || '') === value;
    }

    function filterOptions($select, predicate) {
        const selected = String($select.val() || '');
        let selectedIsValid = selected === '';

        $select.find('option').each(function () {
            const $option = $(this);

            if (String($option.val() || '') === '') {
                $option.prop('disabled', false);
                return;
            }

            const enabled = predicate($option);
            $option.prop('disabled', !enabled);

            if (enabled && String($option.val()) === selected) {
                selectedIsValid = true;
            }
        });

        if (!selectedIsValid) {
            $select.val(null);
        }

        $select.trigger('change.select2');
    }

    function selectedBranchType() {
        return String($('#stock-balance-branch option:selected').data('branch-type') || '');
    }

    function syncScope() {
        const branch = String($('#stock-balance-branch').val() || '');
        const $store = $('#stock-balance-store');
        const $hall = $('#stock-balance-hall');

        filterOptions($store, function ($option) {
            return optionMatches($option, 'branch', branch);
        });

        filterOptions($hall, function ($option) {
            return optionMatches($option, 'branch', branch);
        });

        const showHall = branch !== '' && selectedBranchType() === 'factory';
        $('#stock-balance-hall-field').toggleClass('d-none', !showHall);

        if (!showHall && $hall.val()) {
            $hall.val(null).trigger('change.select2');
        }
    }

    $(function () {
        if (window.AppReportUI && typeof window.AppReportUI.init === 'function') {
            window.AppReportUI.init(document);
        }

        $('#stock-balance-branch').on('change.stockBalanceScope', syncScope);
        $('#stock-balance-store').on('change.stockBalanceScope', syncScope);
        syncScope();
    });
})(jQuery, window, document);
