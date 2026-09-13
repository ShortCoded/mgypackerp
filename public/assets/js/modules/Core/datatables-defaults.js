(function (window, $) {
    'use strict';

    const noColvisSelector = ':not(.no-colvis)';
    const maxPageLength = 100;
    const lengthMenuValues = [10, 25, 50, 75, maxPageLength];
    let dropdownOverflowBound = false;
    let selectAllCellBound = false;
    let activeDropdownPortal = null;

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

    function enhancementRoot(root) {
        const $root = $(root || document);

        if (!root || root === document) {
            return $root;
        }

        const $card = $root.closest('.erp-datatable-card');

        return $card.length ? $card : $root;
    }

    function enhancementSelector($root, selector) {
        return $root.is('.erp-datatable-card') ? selector : '.erp-datatable-card ' + selector;
    }

    function applyFalconEnhancements(root) {
        const $root = enhancementRoot(root);

        $root.find(enhancementSelector($root, '.dataTables_filter input, .dt-search input')).addClass('form-control-sm');
        $root.find(enhancementSelector($root, '.dataTables_length select, .dt-length select')).addClass('form-select-sm');
        $root.find(enhancementSelector($root, '.dt-buttons .btn')).removeClass('btn-secondary').addClass('btn-falcon-default btn-sm');
        bindDropdownOverflow();
        bindSelectAllCell();

        if (window.AppShortcuts && typeof window.AppShortcuts.applyDataTableSearchTitles === 'function') {
            window.AppShortcuts.applyDataTableSearchTitles($root.get(0) || root || document);
        }
    }

    function bindDropdownOverflow() {
        if (dropdownOverflowBound) {
            return;
        }

        dropdownOverflowBound = true;

        const selector = [
            '.erp-datatable-card .dropdown',
            '.erp-datatable-card .dropstart',
            '.erp-datatable-card .dropend',
            '.erp-datatable-card .dropup',
            '.erp-datatable-card .btn-reveal-trigger'
        ].join(', ');

        $(document)
            .off('show.bs.dropdown.erpDataTables', selector)
            .on('show.bs.dropdown.erpDataTables', selector, function () {
                $(this)
                    .closest('.dataTables_scrollBody, .dt-scroll-body, .erp-datatable-scroll')
                    .addClass('datatable-dropdown-open');
            })
            .off('shown.bs.dropdown.erpDataTables', selector)
            .on('shown.bs.dropdown.erpDataTables', selector, function () {
                portalDropdownMenu(this);
            })
            .off('hidden.bs.dropdown.erpDataTables', selector)
            .on('hidden.bs.dropdown.erpDataTables', selector, function () {
                restoreDropdownMenu(this);
                $(this)
                    .closest('.dataTables_scrollBody, .dt-scroll-body, .erp-datatable-scroll')
                    .removeClass('datatable-dropdown-open');
            });

        window.addEventListener('resize', positionPortalDropdown, { passive: true });
        window.addEventListener('scroll', positionPortalDropdown, { passive: true, capture: true });
    }

    function portalDropdownMenu(dropdown) {
        restoreDropdownMenu();

        const menu = dropdown.querySelector('.dropdown-menu');
        const toggle = dropdown.querySelector('[data-bs-toggle="dropdown"], .dropdown-toggle');

        if (!menu || !toggle) {
            return;
        }

        const placeholder = document.createComment('datatable-actions-menu');
        menu.parentNode.insertBefore(placeholder, menu);
        document.body.appendChild(menu);
        menu.classList.add('erp-datatable-dropdown-portal');
        menu.style.position = 'fixed';
        menu.style.inset = 'auto';
        menu.style.margin = '0';
        menu.style.zIndex = '1095';
        menu.style.transform = 'none';
        activeDropdownPortal = { dropdown: dropdown, menu: menu, placeholder: placeholder, toggle: toggle };
        positionPortalDropdown();
    }

    function positionPortalDropdown() {
        if (!activeDropdownPortal) {
            return;
        }

        const toggleRect = activeDropdownPortal.toggle.getBoundingClientRect();
        const menu = activeDropdownPortal.menu;
        const menuRect = menu.getBoundingClientRect();
        const gap = 4;
        const padding = 8;
        const alignEnd = menu.classList.contains('dropdown-menu-end');
        let left = alignEnd ? toggleRect.right - menuRect.width : toggleRect.left;
        let top = toggleRect.bottom + gap;

        left = Math.max(padding, Math.min(left, window.innerWidth - menuRect.width - padding));
        if (top + menuRect.height > window.innerHeight - padding && toggleRect.top - menuRect.height - gap >= padding) {
            top = toggleRect.top - menuRect.height - gap;
        }

        menu.style.setProperty('--erp-datatable-dropdown-left', Math.round(left) + 'px');
        menu.style.setProperty('--erp-datatable-dropdown-top', Math.round(Math.max(padding, top)) + 'px');
    }

    function restoreDropdownMenu(dropdown) {
        if (!activeDropdownPortal || (dropdown && activeDropdownPortal.dropdown !== dropdown)) {
            return;
        }

        const portal = activeDropdownPortal;
        activeDropdownPortal = null;

        if (portal.placeholder.parentNode) {
            portal.placeholder.parentNode.insertBefore(portal.menu, portal.placeholder);
            portal.placeholder.remove();
        }
        portal.menu.classList.remove('erp-datatable-dropdown-portal');
        portal.menu.style.position = '';
        portal.menu.style.inset = '';
        portal.menu.style.margin = '';
        portal.menu.style.zIndex = '';
        portal.menu.style.transform = '';
        portal.menu.style.left = '';
        portal.menu.style.top = '';
        portal.menu.style.removeProperty('--erp-datatable-dropdown-left');
        portal.menu.style.removeProperty('--erp-datatable-dropdown-top');
    }

    function bindSelectAllCell() {
        if (selectAllCellBound) {
            return;
        }

        selectAllCellBound = true;

        $(document)
            .off('click.erpDataTableSelectAll', '.erp-datatable-card thead th.dt-select')
            .on('click.erpDataTableSelectAll', '.erp-datatable-card thead th.dt-select', function (event) {
                if ($(event.target).closest('input, label, button, a').length > 0) {
                    return;
                }

                const checkbox = this.querySelector('input[type="checkbox"]');

                if (!checkbox || checkbox.disabled) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                checkbox.click();
            });
    }

    function cappedPageLength(value) {
        const pageLength = Number(value);

        if (!Number.isFinite(pageLength) || pageLength < 1 || pageLength > maxPageLength) {
            return maxPageLength;
        }

        return pageLength;
    }

    function normalizeStatePageLength(settings, data) {
        if (!data || !Object.prototype.hasOwnProperty.call(data, 'length')) {
            return;
        }

        data.length = cappedPageLength(data.length);
    }

    function statePageLengthCallback(callback) {
        return function (settings, data) {
            normalizeStatePageLength(settings, data);

            if (typeof callback === 'function') {
                return callback.apply(this, arguments);
            }
        };
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
            autoWidth: false,
            orderClasses: false,
            pagingType: 'full_numbers',
            pageLength: 10,
            lengthMenu: lengthMenu(),
            processing: true,
            searchDelay: 400,
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
            stateLoadParams: normalizeStatePageLength,
            stateSaveParams: normalizeStatePageLength,
            drawCallback: function () {
                const api = this && typeof this.api === 'function' ? this.api() : null;
                const table = api && typeof api.table === 'function' ? api.table() : null;
                const container = table && typeof table.container === 'function' ? table.container() : document;

                applyFalconEnhancements(container);
            }
        };
    }

    function options(overrides) {
        const merged = $.extend(true, {}, defaults(), overrides || {});

        merged.pageLength = cappedPageLength(merged.pageLength);
        merged.stateLoadParams = statePageLengthCallback(overrides && overrides.stateLoadParams);
        merged.stateSaveParams = statePageLengthCallback(overrides && overrides.stateSaveParams);

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
