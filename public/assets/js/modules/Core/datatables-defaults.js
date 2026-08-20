(function (window, $) {
    'use strict';

    const noColvisSelector = ':not(.no-colvis)';
    const lengthMenuValues = [10, 25, 50, 75, 100, 125, 150, 200, 225, 250, 275, 300];

    function translations() {
        return window.dataTableTranslations || {};
    }

    function lengthMenu() {
        return [
            lengthMenuValues.slice(),
            lengthMenuValues.slice()
        ];
    }

    function columnVisibilityLabel() {
        const labels = translations();

        return labels.column_visibility || (labels.buttons && labels.buttons.column_visibility) || '';
    }

    function columnVisibilityButton(overrides) {
        return $.extend(true, {
            extend: 'colvis',
            columns: noColvisSelector,
            text: '<span class="fas fa-columns me-1" data-fa-transform="shrink-3"></span><span>' + columnVisibilityLabel() + '</span>',
            className: 'btn btn-falcon-default btn-sm',
            collectionLayout: 'fixed two-column'
        }, overrides || {});
    }

    function applyFalconEnhancements(root) {
        const $root = $(root || document);

        $root.find('.erp-datatable-card .dataTables_filter input, .erp-datatable-card .dt-search input').addClass('form-control-sm');
        $root.find('.erp-datatable-card .dataTables_length select, .erp-datatable-card .dt-length select').addClass('form-select-sm');
        $root.find('.erp-datatable-card .dt-buttons .btn').removeClass('btn-secondary').addClass('btn-falcon-default btn-sm');
        bindDropdownOverflow($root);

        if (window.AppShortcuts && typeof window.AppShortcuts.applyDataTableSearchTitles === 'function') {
            window.AppShortcuts.applyDataTableSearchTitles(root || document);
        }
    }

    function bindDropdownOverflow($root) {
        const selector = '.erp-datatable-card .dropdown, .erp-datatable-card .btn-reveal-trigger';

        $root
            .off('show.bs.dropdown.erpDataTables', selector)
            .on('show.bs.dropdown.erpDataTables', selector, function () {
                $(this)
                    .closest('.dataTables_scrollBody, .dt-scroll-body, .erp-datatable-scroll')
                    .addClass('datatable-dropdown-open');
            })
            .off('hidden.bs.dropdown.erpDataTables', selector)
            .on('hidden.bs.dropdown.erpDataTables', selector, function () {
                $(this)
                    .closest('.dataTables_scrollBody, .dt-scroll-body, .erp-datatable-scroll')
                    .removeClass('datatable-dropdown-open');
            });
    }

    function resolveColumnIndex(index, totalColumns) {
        return index < 0 ? totalColumns + index : index;
    }

    function protectStateColumns(data, indexes) {
        const columns = data && data.columns ? data.columns : [];

        (indexes || []).forEach(function (index) {
            const resolvedIndex = resolveColumnIndex(index, columns.length);

            if (columns[resolvedIndex]) {
                columns[resolvedIndex].visible = true;
            }
        });
    }

    function showColumns(api, indexes) {
        if (!api || typeof api.columns !== 'function') {
            return;
        }

        const totalColumns = api.columns().count();

        (indexes || []).forEach(function (index) {
            const resolvedIndex = resolveColumnIndex(index, totalColumns);

            if (resolvedIndex >= 0 && resolvedIndex < totalColumns) {
                api.column(resolvedIndex).visible(true, false);
            }
        });

        api.columns.adjust();

        if (api.responsive && typeof api.responsive.recalc === 'function') {
            api.responsive.recalc();
        }
    }

    function defaults() {
        return {
            pagingType: 'full_numbers',
            pageLength: 10,
            lengthMenu: lengthMenu(),
            responsive: {
                details: {
                    type: 'inline',
                    target: 1
                }
            },
            dom: '<"row g-3 align-items-center px-3 py-3"<"col-12 col-xl-4"l><"col-12 col-xl-4 d-flex justify-content-xl-center"B><"col-12 col-xl-4"f>>rt<"row g-0 px-3 py-2 align-items-center border-top"<"col-sm-12 col-md-6 d-flex justify-content-center justify-content-md-start"i><"col-sm-12 col-md-6 d-flex justify-content-center justify-content-md-end"p>>',
            buttons: [
                columnVisibilityButton()
            ],
            language: translations(),
            drawCallback: function () {
                applyFalconEnhancements(document);
            }
        };
    }

    function options(overrides) {
        const merged = $.extend(true, {}, defaults(), overrides || {});

        if (overrides && Object.prototype.hasOwnProperty.call(overrides, 'buttons')) {
            merged.buttons = overrides.buttons;
        }

        return merged;
    }

    function wideOptions(overrides) {
        return options($.extend(true, {
            autoWidth: false,
            scrollCollapse: true,
            scrollX: true,
            responsive: false
        }, overrides || {}));
    }

    window.AppDataTables = {
        applyFalconEnhancements: applyFalconEnhancements,
        columnVisibilityButton: columnVisibilityButton,
        defaults: defaults,
        lengthMenu: lengthMenu,
        lengthMenuValues: lengthMenuValues.slice(),
        noColvisSelector: noColvisSelector,
        options: options,
        protectStateColumns: protectStateColumns,
        showColumns: showColumns,
        wideOptions: wideOptions
    };
})(window, jQuery);
