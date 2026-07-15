(function ($, window, document) {
    'use strict';

    function optionList(values) {
        return $.isArray(values) ? values : [];
    }

    function filterData(options) {
        const settings = options || {};
        const allowedFilterNames = settings.allowedFilterNames ? optionList(settings.allowedFilterNames) : null;
        const data = {};

        $(settings.filterSelector || '.js-report-filters').serializeArray().forEach(function (field) {
            if (allowedFilterNames && allowedFilterNames.indexOf(field.name) === -1) { return; }

            const value = String(field.value || '').trim();

            if (value !== '') { data[field.name] = value; }
        });

        return data;
    }

    function sanitizeSavedState(data, removedFilterNames) {
        if (!data) { return; }

        optionList(removedFilterNames).forEach(function (name) {
            delete data[name];
        });

        ['filters', 'filter', 'customFilters', 'ajaxFilters', 'reportFilters'].forEach(function (key) {
            if (!data[key] || typeof data[key] !== 'object') { return; }

            optionList(removedFilterNames).forEach(function (name) {
                delete data[key][name];
            });
        });
    }

    function protectStateColumns(data, protectedColumns) {
        if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
            window.AppDataTables.protectStateColumns(data, optionList(protectedColumns));
        }
    }

    function initEnhancements(root) {
        if (window.AppSelect2Ajax && window.AppSelect2Ajax.init) {
            window.AppSelect2Ajax.init(root || document);
        }

        if (window.AppDatePicker && window.AppDatePicker.init) {
            window.AppDatePicker.init(root || document);
        }
    }

    function clearRemovedFilters($form, removedFilterNames) {
        optionList(removedFilterNames).forEach(function (name) {
            $form.find('[name="' + name + '"]').each(function () {
                const $field = $(this);

                $field.val('');

                if ($field.hasClass('js-select2-ajax') || $field.hasClass('js-select2-local')) {
                    $field.trigger('change.select2');
                }
            });
        });
    }

    function resetFilters($form, options) {
        const settings = options || {};

        if (!$form || $form.length === 0) { return; }

        $form.get(0).reset();

        if (window.AppDatePicker && window.AppDatePicker.clear) {
            window.AppDatePicker.clear($form.get(0));
        }

        $form.find('.js-select2-ajax[data-allow-clear="true"]').val(null).trigger('change.select2');
        $form.find('.js-select2-local').trigger('change.select2');
        clearRemovedFilters($form, settings.removedFilterNames);
    }

    function removeStoredTableState($table) {
        const id = String($table.attr('id') || '');

        if (id === '') { return; }

        [window.localStorage, window.sessionStorage].forEach(function (store) {
            if (!store) { return; }

            try {
                for (let index = store.length - 1; index >= 0; index -= 1) {
                    const key = store.key(index);

                    if (key && key.indexOf('DataTables_' + id + '_') === 0) {
                        store.removeItem(key);
                    }
                }
            } catch (error) {
                return;
            }
        });
    }

    function resetTableState(table, $table, defaultOrder) {
        if (table.state && typeof table.state.clear === 'function') {
            table.state.clear();
        }

        removeStoredTableState($table);

        table.search('');
        table.columns().search('');

        if (defaultOrder) {
            table.order(defaultOrder);
        }

        table.page('first');
    }

    function exportUrl($link, options) {
        const query = $.param(filterData(options));

        return $link.attr('href') + (query ? '?' + query : '');
    }

    function bindFilters(table, $table, options) {
        const settings = options || {};
        const filterSelector = settings.filterSelector || '.js-report-filters';
        const filterControlSelector = settings.filterControlSelector || '.js-report-filter-control';
        let isResetting = false;

        $(filterSelector).off('submit.reportFilters').on('submit.reportFilters', function (event) {
            event.preventDefault();
            table.ajax.reload();
        });

        $(filterControlSelector).off('change.reportFilters').on('change.reportFilters', function () {
            if (isResetting) { return; }

            table.ajax.reload();
        });

        $(document).off('click.reportFilters', settings.resetSelector || '.js-report-reset').on('click.reportFilters', settings.resetSelector || '.js-report-reset', function () {
            const $form = $(filterSelector);

            isResetting = true;
            resetFilters($form, settings);

            if (settings.resetTableState) {
                resetTableState(table, $table, settings.defaultOrder);
            }

            isResetting = false;
            table.ajax.reload();
        });

        $('.js-report-refresh').off('click.reportRefresh').on('click.reportRefresh', function () {
            table.ajax.reload(null, false);
        });

        $('.js-report-export').off('click.reportExport').on('click.reportExport', function (event) {
            event.preventDefault();

            const $link = $(this);
            const url = exportUrl($link, settings);

            if ($link.data('open-in-new-tab') || $link.attr('target') === '_blank') {
                window.open(url, '_blank', 'noopener');
                return;
            }

            window.location.href = url;
        });
    }

    function textValue(value) {
        if (value === null || value === undefined) { return ''; }

        const text = String(value).trim();

        return ['', '-', '—', 'Not available', 'N/A', 'غير متاح', 'لا يوجد'].indexOf(text) !== -1 ? '' : text;
    }

    function renderValue(value, item) {
        if (item && item.type === 'json') {
            return $('<pre dir="ltr" class="report-technical-json report-json-details mb-0"></pre>').text(textValue(value));
        }

        if (item && item.type === 'link' && item.url) {
            return $('<a target="_blank" rel="noopener noreferrer"></a>')
                .attr('href', item.url)
                .text(textValue(value));
        }

        if ($.isArray(value)) {
            if (value.length === 0) {
                return $('<span></span>');
            }

            const $list = $('<ul class="mb-0 ps-3"></ul>');
            value.forEach(function (item) {
                $list.append($('<li></li>').text(textValue(item)));
            });
            return $list;
        }

        return $('<span></span>').text(textValue(value));
    }

    function renderSection(section, index, sectionIdPrefix) {
        const $table = $('<div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody></tbody></table></div>');
        const $body = $table.find('tbody');
        const items = section.items || [];

        items.forEach(function (item) {
            const $row = $('<tr><th class="text-nowrap w-25"></th><td></td></tr>');
            $row.find('th').text(item.label || '');
            $row.find('td').append(renderValue(item.value, item));
            $body.append($row);
        });

        if (section.collapsed) {
            const collapseId = sectionIdPrefix + '-' + index;
            const $wrapper = $('<div class="border rounded-2 mb-3 overflow-hidden"></div>');
            const $button = $('<button class="btn btn-falcon-default btn-sm w-100 text-start rounded-0" type="button" data-bs-toggle="collapse"></button>');
            const $collapse = $('<div class="collapse"></div>').attr('id', collapseId);
            $button.attr('data-bs-target', '#' + collapseId).text(section.title || '');
            $collapse.append($('<div class="p-3"></div>').append($table));
            return $wrapper.append($button).append($collapse);
        }

        return $('<div class="mb-3"></div>')
            .append($('<h6 class="text-700 mb-2"></h6>').text(section.title || ''))
            .append($table);
    }

    function detailsHtml(data, options) {
        const settings = options || {};
        const sectionIdPrefix = settings.sectionIdPrefix || 'report-details-section';

        if (data && $.isArray(data.sections)) {
            const $content = $('<div></div>');

            if (data.summary) {
                $content.append($('<div class="alert alert-info py-2 px-3 mb-3"></div>').text(data.summary));
            }

            data.sections.forEach(function (section, index) {
                $content.append(renderSection(section, index, sectionIdPrefix));
            });

            return $content;
        }

        const $table = $('<div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody></tbody></table></div>');
        const $body = $table.find('tbody');

        $.each(data || {}, function (key, value) {
            const $row = $('<tr><th class="text-nowrap w-25"></th><td></td></tr>');
            $row.find('th').text(key);
            $row.find('td').append(renderValue(value, {}));
            $body.append($row);
        });

        return $table;
    }

    function initDetails(options) {
        const settings = options || {};

        $(document).off('click.reportDetails', '.js-report-details').on('click.reportDetails', '.js-report-details', function () {
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById(settings.modalId || 'report-details-modal'));
            $.getJSON($(this).data('details-url')).done(function (response) {
                $('#report-details-modal-title').text(response.title || '');
                $('.js-report-details-content').empty().append(detailsHtml(response.data || {}, settings));
                modal.show();
            }).fail(function () {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        text: (window.reportMessages || {}).detailsLoadFailed || 'Unable to load details.',
                        showCloseButton: true,
                        allowEscapeKey: true,
                        heightAuto: false
                    });
                }
            });
        });
    }

    function toggleIcon($toggle, expanded) {
        const $indicator = $toggle.find('.report-filter-toggle-indicator');

        if ($indicator.length === 0) { return; }

        $indicator
            .toggleClass('fa-chevron-down', !expanded)
            .toggleClass('fa-chevron-up', expanded);
    }

    function syncFilterToggle($collapse) {
        const id = $collapse.attr('id');

        if (!id) { return; }

        const expanded = $collapse.hasClass('show');
        $('[data-report-filter-toggle][data-bs-target="#' + id + '"]').each(function () {
            const $toggle = $(this);
            $toggle.attr('aria-expanded', expanded ? 'true' : 'false');
            toggleIcon($toggle, expanded);
        });
    }

    function initFilterToggles(root) {
        $(root || document).find('.report-filter-collapse').each(function () {
            syncFilterToggle($(this));
        });

        $(document)
            .off('shown.bs.collapse.reportFilterToggle hidden.bs.collapse.reportFilterToggle', '.report-filter-collapse')
            .on('shown.bs.collapse.reportFilterToggle hidden.bs.collapse.reportFilterToggle', '.report-filter-collapse', function () {
                syncFilterToggle($(this));
            });
    }

    function bindActionDropdowns() {
        $(document)
            .off('show.bs.dropdown.reportActions hide.bs.dropdown.reportActions', '.report-table-card .dropdown')
            .on('show.bs.dropdown.reportActions', '.report-table-card .dropdown', function () {
                $(this).closest('.erp-datatable-scroll').addClass('datatable-dropdown-open');
            })
            .on('hide.bs.dropdown.reportActions', '.report-table-card .dropdown', function () {
                $(this).closest('.erp-datatable-scroll').removeClass('datatable-dropdown-open');
            });
    }

    function init(root) {
        initEnhancements(root || document);
        initFilterToggles(root || document);
        bindActionDropdowns();
    }

    window.AppReportUI = {
        bindActionDropdowns: bindActionDropdowns,
        bindFilters: bindFilters,
        detailsHtml: detailsHtml,
        filterData: filterData,
        init: init,
        initDetails: initDetails,
        initEnhancements: initEnhancements,
        initFilterToggles: initFilterToggles,
        protectStateColumns: protectStateColumns,
        removeStoredTableState: removeStoredTableState,
        resetFilters: resetFilters,
        resetTableState: resetTableState,
        sanitizeSavedState: sanitizeSavedState
    };
})(jQuery, window, document);
