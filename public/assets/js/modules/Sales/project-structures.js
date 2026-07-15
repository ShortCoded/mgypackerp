(function ($, window, document) {
    'use strict';

    const messages = window.projectStructureMessages || {};
    const selectedDocNums = new Set();
    let structuresTable = null;
    let treeVisible = false;

    function csrfToken() {
        return $('meta[name="csrf-token"]').attr('content');
    }

    function headers() {
        return {
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json'
        };
    }

    function toast(icon, title) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
        }
    }

    function confirmDialog(options) {
        if (!window.Swal) {
            return $.Deferred().resolve({ isConfirmed: false }).promise();
        }

        return Swal.fire({
            icon: 'warning',
            title: options.title,
            text: options.text,
            showCloseButton: true,
            showCancelButton: true,
            focusCancel: true,
            allowEscapeKey: true,
            confirmButtonText: options.confirmButtonText,
            cancelButtonText: options.cancelButtonText || messages.cancel || '',
            confirmButtonColor: options.confirmButtonColor || '#d33',
            cancelButtonColor: '#748194'
        });
    }

    function escapeHtml(value) {
        return $('<div></div>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    function formData($form) {
        return {
            doc_number: String($form.find('[name="doc_number"]').val() || '').trim(),
            name: String($form.find('[name="name"]').val() || '').trim(),
            code: String($form.find('[name="code"]').val() || '').trim(),
            parent_doc_num: String($form.find('[name="parent_doc_num"]').val() || '').trim(),
            status: String($form.find('[name="status"]').val() || '').trim(),
            notes: String($form.find('[name="notes"]').val() || '').trim()
        };
    }

    function originalFormData($form) {
        const original = $form.data('original') || {};

        return {
            doc_number: original.doc_number === null || original.doc_number === undefined ? '' : String(original.doc_number).trim(),
            name: String(original.name || '').trim(),
            code: String(original.code || '').trim(),
            parent_doc_num: String(original.parent_doc_num || '').trim(),
            status: String(original.status || '').trim(),
            notes: String(original.notes || '').trim()
        };
    }

    function hasChanges($form) {
        if ($form.data('mode') !== 'edit') {
            return true;
        }

        const original = originalFormData($form);
        const current = formData($form);

        return Object.keys(original).some(function (field) {
            return original[field] !== current[field];
        });
    }

    function alertElement($form) {
        let $alert = $form.find('.js-project-structure-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger d-none js-project-structure-alert" role="alert"><div class="js-project-structure-alert-message"></div></div>');
            $form.find('.js-project-structure-form-body, .card-body').first().prepend($alert);
        }

        return $alert;
    }

    function validationMessages(errors) {
        const result = [];

        Object.keys(errors || {}).forEach(function (field) {
            const values = $.isArray(errors[field]) ? errors[field] : [errors[field]];

            values.forEach(function (message) {
                if (message) {
                    result.push(message);
                }
            });
        });

        return result;
    }

    function showValidationErrors($form, errors) {
        const $list = $('<ul class="mb-0 ps-3"></ul>');

        clearFormErrors($form);

        validationMessages(errors).forEach(function (message) {
            $list.append($('<li></li>').text(message));
        });

        alertElement($form)
            .removeClass('d-none alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-project-structure-alert-message')
            .empty()
            .append($list);

        Object.keys(errors || {}).forEach(function (field) {
            const normalizedField = field.replace(/\.\d+$/, '');
            const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
            const $input = $form.find('[name="' + normalizedField + '"], [name="' + normalizedField + '[]"]');

            $input.addClass('is-invalid');
            $form.find('[data-error-for="' + normalizedField + '"]').text(message || '');
        });
    }

    function showFormNotice($form, message, type) {
        alertElement($form)
            .removeClass('d-none alert-danger alert-warning alert-success alert-info')
            .addClass('alert-' + (type || 'danger'))
            .find('.js-project-structure-alert-message')
            .text(message || messages.unexpectedError);
    }

    function clearFormErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        alertElement($form)
            .addClass('d-none')
            .removeClass('alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-project-structure-alert-message')
            .empty();
    }

    function setLoading($element, loading) {
        $element.prop('disabled', loading);
        $element.css('cursor', loading ? 'wait' : '');
        $('body').css('cursor', loading ? 'wait' : '');
    }

    function updateOriginalFormData($form) {
        $form.data('original', formData($form));
    }

    function resetCreateForm($form, response) {
        $form.find('[name="name"], [name="code"], [name="notes"]').val('');
        $form.find('[name="status"]').val('active');
        $form.find('[name="doc_number"]').val(response.next_doc_number || '');
        $form.find('[name="parent_doc_num"]').val(null).trigger('change');
        $form.find('[name="submit_action"]').val('save_new');
        $form.find('[name="clone_source_token"]').remove();
        updateOriginalFormData($form);
        $form.find('[name="name"]').trigger('focus');
    }

    function updateUrlsAfterDocNumberChange($form, response) {
        const data = response && response.data ? response.data : {};
        const urls = data.urls || {};

        if (!data.old_doc_num || !data.doc_num || data.old_doc_num === data.doc_num) {
            return;
        }

        if (urls.update) {
            $form.attr('action', urls.update);
        }

        $('a[href*="' + data.old_doc_num + '"]').each(function () {
            const $link = $(this);
            $link.attr('href', String($link.attr('href')).replace(data.old_doc_num, data.doc_num));

            if ($.trim($link.text()) === data.old_doc_num) {
                $link.text(data.doc_num);
            }
        });

        if (window.history && window.location.pathname.indexOf(data.old_doc_num) !== -1) {
            window.history.replaceState({}, '', window.location.pathname.replace(data.old_doc_num, data.doc_num) + window.location.search + window.location.hash);
        }
    }

    function checkboxDocNum(checkbox) {
        return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
    }

    function pageCheckboxes(api) {
        const selector = 'input.js-record-select, input.js-project-structure-row-checkbox';

        return api && typeof api.rows === 'function'
            ? $(api.rows({ page: 'current' }).nodes()).find(selector)
            : $('.js-project-structures-table').find('tbody tr:not(.child) ' + selector);
    }

    function trashFilterValue() {
        const value = String($('#project_structures_trash_filter').val() || 'active');

        return ['active', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
    }

    function updateBulkActionsUi() {
        const selectedCount = selectedDocNums.size;
        const $actions = $('#bulk_actions_bar');
        const $applyButton = $('#bulk_action_apply');
        const applyLabel = $applyButton.data('label') || '';

        $actions.toggleClass('d-none', selectedCount === 0).toggleClass('d-flex', selectedCount > 0);
        $('#bulk_selected_count').text(selectedCount);
        $applyButton
            .prop('disabled', selectedCount === 0)
            .find('span:last')
            .text(applyLabel + (selectedCount > 0 ? ' (' + selectedCount + ')' : ''));

        if (selectedCount === 0) {
            $('#bulk_action_select').val('delete');
        }
    }

    function updateSelectAllState(api) {
        const selectableDocNums = pageCheckboxes(api).map(function () {
            return checkboxDocNum(this);
        }).get().filter(function (docNum) {
            return docNum !== '';
        });
        const checkedOnPage = selectableDocNums.filter(function (docNum) {
            return selectedDocNums.has(docNum);
        }).length;

        $('#select_all_records')
            .prop('checked', selectableDocNums.length > 0 && checkedOnPage === selectableDocNums.length)
            .prop('indeterminate', checkedOnPage > 0 && checkedOnPage < selectableDocNums.length);
    }

    function restoreSelectionState(api) {
        pageCheckboxes(api).each(function () {
            const docNum = checkboxDocNum(this);
            $(this).prop('checked', docNum !== '' && selectedDocNums.has(docNum));
        });

        updateSelectAllState(api);
        updateBulkActionsUi();
    }

    function clearSelection(api) {
        selectedDocNums.clear();
        pageCheckboxes(api).prop('checked', false);
        updateSelectAllState(api);
        updateBulkActionsUi();
    }

    function reloadTable() {
        if (structuresTable) {
            structuresTable.ajax.reload(null, false);
        }
    }

    function initTable() {
        const $table = $('.js-project-structures-table').first();

        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) {
                return options;
            };
        const tableName = String($table.data('table-name') || 'project_structures');

        structuresTable = $table.DataTable(dataTableOptions({
            processing: true,
            serverSide: true,
            stateSave: true,
            ajax: {
                url: $table.data('url'),
                data: function (data) {
                    data.trash_filter = trashFilterValue();
                }
            },
            responsive: {
                details: {
                    type: 'inline',
                    target: 1
                }
            },
            order: [[3, 'asc']],
            columns: [
                { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
                { data: 'doc_num', name: tableName + '.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
                { data: 'name', name: 'name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'code', name: 'code', className: 'align-middle white-space-nowrap dt-code' },
                { data: 'parent', name: 'parent', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'status', name: 'status', className: 'align-middle white-space-nowrap' },
                { data: 'created_by', name: 'created_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'created_at', name: 'created_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'updated_by', name: 'updated_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'updated_at', name: 'updated_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'deleted_by', name: 'deleted_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'deleted_at', name: 'deleted_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap all no-colvis dt-actions' }
            ],
            createdRow: function (row) {
                $(row).addClass('btn-reveal-trigger');
            },
            initComplete: function () {
                restoreSelectionState(this.api());
            },
            drawCallback: function () {
                restoreSelectionState(this.api());

                if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
                    window.AppDataTables.applyFalconEnhancements(document);
                }
            }
        }));

        $('#project_structures_trash_filter').off('change.projectStructuresTrash').on('change.projectStructuresTrash', function () {
            clearSelection(structuresTable);
            reloadTable();
            reloadTree();
        });

        $('#select_all_records').off('change.projectStructuresSelectAll').on('change.projectStructuresSelectAll', function () {
            const checked = $(this).is(':checked');

            pageCheckboxes(structuresTable).each(function () {
                const docNum = checkboxDocNum(this);

                if (docNum === '') {
                    return;
                }

                if (checked) {
                    selectedDocNums.add(docNum);
                } else {
                    selectedDocNums.delete(docNum);
                }

                $(this).prop('checked', checked);
            });

            updateSelectAllState(structuresTable);
            updateBulkActionsUi();
        });

        $table.off('click.projectStructuresSelectCell', 'tbody tr:not(.child) td.dt-select').on('click.projectStructuresSelectCell', 'tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            const $checkbox = $(this).find('input.js-record-select, input.js-project-structure-row-checkbox').first();

            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        });

        $table.off('change.projectStructuresSelect', 'input.js-record-select, input.js-project-structure-row-checkbox').on('change.projectStructuresSelect', 'input.js-record-select, input.js-project-structure-row-checkbox', function () {
            const docNum = checkboxDocNum(this);

            if (docNum === '') {
                return;
            }

            if ($(this).is(':checked')) {
                selectedDocNums.add(docNum);
            } else {
                selectedDocNums.delete(docNum);
            }

            updateSelectAllState(structuresTable);
            updateBulkActionsUi();
        });

        $table.off('dblclick.projectStructuresEditRow', 'tbody tr:not(.child)').on('dblclick.projectStructuresEditRow', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            const editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        });

        $('#bulk_action_apply').off('click.projectStructuresBulk').on('click.projectStructuresBulk', function () {
            const docNums = Array.from(selectedDocNums);

            if (docNums.length === 0 || $('#bulk_action_select').val() !== 'delete') {
                updateBulkActionsUi();
                return;
            }

            confirmDialog({
                title: messages.bulkDeleteConfirmTitle,
                text: (messages.bulkDeleteConfirmText || '').replace(':count', docNums.length),
                confirmButtonText: messages.bulkDeleteConfirmYes
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: $table.data('bulk-delete-url'),
                    method: 'DELETE',
                    data: { doc_nums: docNums },
                    headers: headers()
                }).done(function (response) {
                    clearSelection(structuresTable);
                    reloadTable();
                    reloadTree();
                    toast('success', response.message);
                }).fail(function (response) {
                    toast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                });
            });
        });
    }

    function renderTree(nodes, depth) {
        if (!nodes || nodes.length === 0) {
            return depth === 0 ? '<div class="text-center text-600 py-4">' + escapeHtml(messages.emptyTree || '') + '</div>' : '';
        }

        const role = depth === 0 ? ' role="tree"' : ' role="group"';
        let html = '<ul class="treeview-list ps-0 mb-0 list-unstyled"' + role + '>';

        nodes.forEach(function (node) {
            const children = $.isArray(node.children) ? node.children : [];
            const hasChildren = children.length > 0;
            const nodeId = 'project-structure-node-' + String(node.id || '').replace(/[^A-Za-z0-9_-]/g, '-');

            html += '<li class="treeview-list-item" role="treeitem" aria-expanded="true">';
            html += '<div class="treeview-row d-flex align-items-center py-1" style="padding-inline-start:' + (depth * 1.25) + 'rem">';

            if (hasChildren) {
                html += '<button class="btn btn-link btn-sm p-0 me-2 project-structures-tree-toggle" type="button" data-project-structures-tree-toggle aria-controls="' + escapeHtml(nodeId) + '" aria-expanded="true" title="' + escapeHtml(messages.collapseBranch || '') + '">';
                html += '<span class="fas fa-minus-square"></span>';
                html += '</button>';
            } else {
                html += '<span class="text-400 me-2" aria-hidden="true"><span class="far fa-square"></span></span>';
            }

            html += '<span class="treeview-text d-inline-flex align-items-center min-w-0" data-project-structures-tree-node tabindex="0">';
            html += '<span class="project-structures-tree-code" dir="ltr">' + escapeHtml(node.code) + '</span>';
            html += '<span class="text-700 text-truncate">' + escapeHtml(node.name) + '</span>';
            html += '<span class="badge rounded-pill badge-subtle-secondary ms-2">' + escapeHtml(node.status) + '</span>';
            html += '</span>';
            html += '</div>';

            if (hasChildren) {
                html += '<div id="' + escapeHtml(nodeId) + '">' + renderTree(children, depth + 1) + '</div>';
            }

            html += '</li>';
        });

        html += '</ul>';

        return depth === 0 ? '<div class="treeview">' + html + '</div>' : html;
    }

    function reloadTree() {
        const $tree = $('[data-project-structures-tree]');

        if (!$tree.length || !treeVisible) {
            return;
        }

        const $list = $tree.find('[data-project-structures-tree-list]');

        $list.html('<div class="text-center text-600 py-4"><span class="fas fa-spinner fa-spin me-1"></span></div>');

        $.ajax({
            url: $tree.data('url'),
            method: 'GET',
            dataType: 'json',
            cache: false
        }).done(function (payload) {
            const nodes = payload && $.isArray(payload.data) ? payload.data : [];
            $list.html(renderTree(nodes, 0));
        }).fail(function () {
            $list.html('<div class="text-danger p-3">' + escapeHtml(messages.unexpectedError || '') + '</div>');
        });
    }

    function toggleTreeView() {
        treeVisible = !treeVisible;

        $('[data-project-structures-list]').toggleClass('d-none', treeVisible);
        $('[data-project-structures-tree]').toggleClass('d-none', !treeVisible);
        $('[data-project-structures-tree-controls]').toggleClass('d-none', !treeVisible).toggleClass('d-flex', treeVisible);
        $('[data-project-structures-toggle-tree]').attr('aria-pressed', treeVisible ? 'true' : 'false');
        $('[data-project-structures-toggle-tree]').find('span').toggleClass('fa-sitemap', !treeVisible).toggleClass('fa-list', treeVisible);
        $('[data-project-structures-toggle-tree]').contents().filter(function () {
            return this.nodeType === 3;
        }).remove();
        $('[data-project-structures-toggle-tree]').append(treeVisible ? messages.showList : messages.showTree);

        reloadTree();
    }

    function initTree() {
        $(document).off('click.projectStructuresToggleTree', '[data-project-structures-toggle-tree]').on('click.projectStructuresToggleTree', '[data-project-structures-toggle-tree]', function () {
            toggleTreeView();
        });

        $(document).off('click.projectStructuresTreeNodeToggle', '[data-project-structures-tree-toggle]').on('click.projectStructuresTreeNodeToggle', '[data-project-structures-tree-toggle]', function () {
            const $button = $(this);
            const $target = $('#' + $button.attr('aria-controls'));
            const expanded = $button.attr('aria-expanded') !== 'false';

            $button.attr('aria-expanded', expanded ? 'false' : 'true');
            $button.find('.fas').toggleClass('fa-minus-square', !expanded).toggleClass('fa-plus-square', expanded);
            $button.attr('title', expanded ? messages.expandBranch : messages.collapseBranch);
            $target.toggleClass('d-none', expanded);
        });

        $(document).off('click.projectStructuresTreeExpandAll', '[data-project-structures-tree-expand-all]').on('click.projectStructuresTreeExpandAll', '[data-project-structures-tree-expand-all]', function () {
            $('[data-project-structures-tree-toggle]').attr('aria-expanded', 'true').attr('title', messages.collapseBranch).find('.fas').removeClass('fa-plus-square').addClass('fa-minus-square');
            $('[data-project-structures-tree] .d-none[id^="project-structure-node-"]').removeClass('d-none');
        });

        $(document).off('click.projectStructuresTreeCollapseAll', '[data-project-structures-tree-collapse-all]').on('click.projectStructuresTreeCollapseAll', '[data-project-structures-tree-collapse-all]', function () {
            $('[data-project-structures-tree-toggle]').attr('aria-expanded', 'false').attr('title', messages.expandBranch).find('.fas').removeClass('fa-minus-square').addClass('fa-plus-square');
            $('[data-project-structures-tree] [id^="project-structure-node-"]').addClass('d-none');
        });
    }

    function initDeleteActions() {
        $(document).off('click.projectStructuresDelete', '.js-delete-record[data-delete-url]').on('click.projectStructuresDelete', '.js-delete-record[data-delete-url]', function () {
            const $button = $(this);
            const url = $button.data('delete-url');
            const docNum = String($button.data('doc-num') || '').trim();

            confirmDialog({
                title: messages.deleteConfirmTitle,
                text: messages.deleteConfirmText,
                confirmButtonText: messages.deleteConfirmYes
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                setLoading($button, true);

                $.ajax({ url: url, method: 'DELETE', headers: headers() })
                    .done(function (response) {
                        toast('success', response.message);

                        if ($('.js-project-structures-table').length > 0) {
                            selectedDocNums.delete(docNum);
                            reloadTable();
                            reloadTree();
                            updateBulkActionsUi();
                            return;
                        }

                        window.location.href = $button.data('redirect-url') || $('[data-shortcut-action="form.back"]').attr('href') || '/';
                    })
                    .fail(function (response) {
                        toast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                    })
                    .always(function () {
                        setLoading($button, false);
                    });
            });
        });
    }

    function initRestoreActions() {
        $(document).off('click.projectStructuresRestore', '.js-restore-record[data-restore-url]').on('click.projectStructuresRestore', '.js-restore-record[data-restore-url]', function () {
            const $button = $(this);
            const docNum = String($button.data('doc-num') || '').trim();

            confirmDialog({
                title: messages.restoreConfirmTitle,
                text: messages.restoreConfirmText,
                confirmButtonText: messages.restoreConfirmYes,
                confirmButtonColor: '#00a65a'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                setLoading($button, true);

                $.ajax({ url: $button.data('restore-url'), method: 'PATCH', headers: headers() })
                    .done(function (response) {
                        toast('success', response.message);

                        if ($('.js-project-structures-table').length > 0) {
                            selectedDocNums.delete(docNum);
                            reloadTable();
                            reloadTree();
                            updateBulkActionsUi();
                            return;
                        }

                        window.location.href = $button.data('redirect-url') || window.location.href;
                    })
                    .fail(function (response) {
                        toast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                    })
                    .always(function () {
                        setLoading($button, false);
                    });
            });
        });
    }

    function initForm() {
        $(document).off('click.projectStructuresSubmitAction', '.js-project-structure-submit-action').on('click.projectStructuresSubmitAction', '.js-project-structure-submit-action', function () {
            const $button = $(this);
            $button.closest('form').find('[name="submit_action"]').val(String($button.data('submit-action') || 'save'));
            $button.closest('form').data('submit-button', $button);
        });

        $(document).off('submit.projectStructuresForm', '.js-project-structure-form').on('submit.projectStructuresForm', '.js-project-structure-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $button = $form.data('submit-button') || $form.find('[type="submit"]').first();

            clearFormErrors($form);

            if (!hasChanges($form)) {
                showFormNotice($form, messages.noChanges, 'warning');
                toast('info', messages.noChanges);
                return;
            }

            setLoading($button, true);

            $.ajax({
                url: $form.attr('action'),
                method: $form.attr('method') || 'POST',
                data: $form.serialize(),
                headers: headers()
            }).done(function (response) {
                if (!response.success && response.type === 'no_changes') {
                    showFormNotice($form, response.message || messages.noChanges, 'warning');
                    toast('info', response.message || messages.noChanges);
                    return;
                }

                toast('success', response.message || messages.saved);

                if (response.redirect) {
                    window.location.href = response.redirect;
                    return;
                }

                updateUrlsAfterDocNumberChange($form, response);

                if (response.reset_form) {
                    resetCreateForm($form, response);
                    return;
                }

                updateOriginalFormData($form);
            }).fail(function (response) {
                if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                    showValidationErrors($form, response.responseJSON.errors);
                    toast('error', messages.validationFailed);
                    return;
                }

                const message = response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError;
                showFormNotice($form, message);
                toast('error', message);
            }).always(function () {
                setLoading($button, false);
            });
        });
    }

    function initDocumentNumberSettings() {
        $(document).off('submit.projectStructuresDocumentSettings', '.js-project-structure-document-number-settings-form').on('submit.projectStructuresDocumentSettings', '.js-project-structure-document-number-settings-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $button = $form.find('[type="submit"]').first();

            $form.find('.is-invalid').removeClass('is-invalid');
            $form.find('[data-error-for]').text('');
            setLoading($button, true);

            $.ajax({
                url: $form.attr('action'),
                method: $form.attr('method') || 'POST',
                data: $form.serialize(),
                headers: headers()
            }).done(function (response) {
                toast('success', response.message || messages.saved);
            }).fail(function (response) {
                const errors = response.responseJSON && response.responseJSON.errors ? response.responseJSON.errors : {};

                Object.keys(errors).forEach(function (field) {
                    const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
                    $form.find('[name="' + field + '"]').addClass('is-invalid');
                    $form.find('[data-error-for="' + field + '"]').text(message || '');
                });

                toast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
            }).always(function () {
                setLoading($button, false);
            });
        });
    }

    $(function () {
        initTable();
        initTree();
        initForm();
        initDeleteActions();
        initRestoreActions();
        initDocumentNumberSettings();
    });
})(jQuery, window, document);
