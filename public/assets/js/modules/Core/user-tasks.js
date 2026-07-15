(function ($, window) {
    'use strict';

    var messages = window.coreUserTasksMessages || {};
    var tableSelector = '#user-tasks-table';
    var formSelector = '#user-task-form';
    var selected = new Set();

    function csrfToken() {
        return $('meta[name="csrf-token"]').attr('content');
    }

    function headers() {
        return {
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json'
        };
    }

    function message(key) {
        return messages[key] || '';
    }

    function responseMessage(xhr) {
        return xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : message('unexpectedError');
    }

    function showAlert($container, text, type) {
        if (!$container.length || !text) {
            return;
        }

        $container.html('<div class="alert alert-' + (type || 'danger') + ' mb-3">' + $('<div>').text(text).html() + '</div>');
    }

    function showToast(icon, title) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
        }
    }

    function showInfo(text) {
        if (window.Swal && text) {
            Swal.fire({
                icon: 'info',
                text: text,
                confirmButtonText: message('confirm'),
                showCloseButton: true,
                allowEscapeKey: true,
                heightAuto: false
            });
            return;
        }

        showToast('info', text);
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
            cancelButtonText: message('cancel'),
            confirmButtonColor: '#d33',
            cancelButtonColor: '#748194',
            heightAuto: false
        });
    }

    function clearValidation($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $form.find('[data-form-alert]').empty();
    }

    function renderValidation($form, errors) {
        $.each(errors || {}, function (field, fieldMessages) {
            var normalizedField = String(field || '').replace('[]', '');
            var $field = $form.find('[name="' + normalizedField + '"], [name="' + normalizedField + '[]"]');

            $field.addClass('is-invalid');
            $form.find('[data-error-for="' + normalizedField + '"]').text((fieldMessages || [])[0] || '');
        });
    }

    function initTable() {
        var $table = $(tableSelector);

        if (!$table.length || $.fn.DataTable.isDataTable($table)) {
            return;
        }

        var options = $.extend(true, {}, window.AppDataTables && typeof window.AppDataTables.options === 'function' ? window.AppDataTables.options() : {}, {
            ajax: {
                url: $table.data('ajax-url'),
                data: function (data) {
                    data.trash_filter = $('#user_tasks_trash_filter').val() || 'active';
                }
            },
            stateSave: true,
            order: [[9, 'desc']],
            columns: [
                { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false },
                { data: 'doc_num', name: 'doc_num' },
                { data: 'title', name: 'title' },
                { data: 'type', name: 'type' },
                { data: 'status', name: 'status' },
                { data: 'priority', name: 'priority' },
                { data: 'assigned_to', name: 'assigned_to' },
                { data: 'assigned_by', name: 'assigned_by' },
                { data: 'due_at', name: 'due_at' },
                { data: 'created_at', name: 'created_at' },
                { data: 'actions', name: 'actions', orderable: false, searchable: false }
            ]
        });

        $table.DataTable(options);
    }

    function reloadTable() {
        if ($.fn.DataTable.isDataTable(tableSelector)) {
            $(tableSelector).DataTable().ajax.reload(null, false);
        }
    }

    function selectedDocNums() {
        return Array.from(selected).filter(function (docNum) {
            return String(docNum || '').trim() !== '';
        });
    }

    function clearSelection() {
        selected.clear();
        $(tableSelector).find('.js-record-checkbox').prop('checked', false);
        updateBulkBar();
    }

    function updateBulkBar() {
        var $bar = $('#user-tasks-bulk-actions');
        $bar.toggleClass('d-none', selected.size === 0);
        $bar.find('[data-selected-count]').text(selected.size);
    }

    function updateUrlsAfterSave($form, response) {
        var data = response && response.data ? response.data : {};

        if (data.urls && data.urls.update) {
            $form.attr('action', data.urls.update);
        }

        if (data.urls && data.urls.edit && window.history) {
            window.history.replaceState({}, '', data.urls.edit);
        }

        if (data.old_doc_num && data.doc_num && data.old_doc_num !== data.doc_num) {
            $('a[href*="' + data.old_doc_num + '"]').each(function () {
                var $link = $(this);

                $link.attr('href', String($link.attr('href')).replace(data.old_doc_num, data.doc_num));

                if ($.trim($link.text()) === data.old_doc_num) {
                    $link.text(data.doc_num);
                }
            });
        }
    }

    $(document)
        .off('change.coreUserTasks', tableSelector + ' .js-record-checkbox')
        .on('change.coreUserTasks', tableSelector + ' .js-record-checkbox', function () {
            var docNum = String($(this).data('doc-num') || '').trim();

            if (docNum === '') {
                return;
            }

            if (this.checked) {
                selected.add(docNum);
            } else {
                selected.delete(docNum);
            }

            updateBulkBar();
        })
        .off('change.coreUserTasks', '#user_tasks_trash_filter')
        .on('change.coreUserTasks', '#user_tasks_trash_filter', function () {
            clearSelection();
            reloadTable();
        })
        .off('click.coreUserTasks', '.js-delete-record')
        .on('click.coreUserTasks', '.js-delete-record', function () {
            var $button = $(this);
            var url = $button.data('url');
            var docNum = String($button.data('doc-num') || '').trim();

            confirmDialog({
                title: message('deleteConfirmTitle'),
                text: message('deleteConfirmText'),
                confirmButtonText: message('deleteConfirmYes')
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: url,
                    type: 'DELETE',
                    headers: headers()
                }).done(function (response) {
                    if (docNum !== '') {
                        selected.delete(docNum);
                    }

                    updateBulkBar();
                    reloadTable();
                    showToast('success', response && response.message ? response.message : message('deleted'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        })
        .off('click.coreUserTasks', '.js-restore-record')
        .on('click.coreUserTasks', '.js-restore-record', function () {
            $.ajax({
                url: $(this).data('url'),
                type: 'PATCH',
                headers: headers()
            }).done(function (response) {
                reloadTable();
                showToast('success', response && response.message ? response.message : message('saved'));
            }).fail(function (xhr) {
                showToast('error', responseMessage(xhr));
            });
        })
        .off('dblclick.coreUserTasks', tableSelector + ' tbody tr')
        .on('dblclick.coreUserTasks', tableSelector + ' tbody tr', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle').length > 0) {
                return;
            }

            var editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        })
        .off('click.coreUserTasks', '[data-bulk-delete-url]')
        .on('click.coreUserTasks', '[data-bulk-delete-url]', function () {
            var url = $(this).data('bulk-delete-url');
            var docNums = selectedDocNums();

            if (docNums.length === 0) {
                showToast('info', message('noRecordsSelected'));
                return;
            }

            confirmDialog({
                title: message('bulkDeleteConfirmTitle'),
                text: message('bulkDeleteConfirmText').replace(':count', docNums.length),
                confirmButtonText: message('bulkDeleteConfirmYes')
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: url,
                    type: 'DELETE',
                    headers: headers(),
                    data: { doc_nums: docNums }
                }).done(function (response) {
                    clearSelection();
                    reloadTable();
                    showToast('success', response && response.message ? response.message : message('bulkDeleted'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        });

    $(document)
        .off('submit.coreUserTasks', formSelector)
        .on('submit.coreUserTasks', formSelector, function (event) {
            event.preventDefault();

            var $form = $(this);
            clearValidation($form);

            $.ajax({
                url: $form.attr('action'),
                type: $form.find('[name="_method"]').val() || $form.attr('method') || 'POST',
                headers: headers(),
                data: $form.serialize()
            }).done(function (response) {
                if (response && response.success === false && response.type === 'no_changes') {
                    showAlert($form.find('[data-form-alert]'), response.message || message('noChanges'), 'warning');
                    showInfo(response.message || message('noChanges'));
                    return;
                }

                updateUrlsAfterSave($form, response);
                showToast('success', response && response.message ? response.message : message('saved'));

                if (response && response.redirect) {
                    window.location.href = response.redirect;
                    return;
                }

                if (response && response.message) {
                    showAlert($form.find('[data-form-alert]'), response.message, 'success');
                }
            }).fail(function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    renderValidation($form, xhr.responseJSON.errors);
                    showAlert($form.find('[data-form-alert]'), message('validationFailed'), 'danger');
                    return;
                }

                showAlert($form.find('[data-form-alert]'), responseMessage(xhr), 'danger');
            });
        })
        .off('click.coreUserTasks', formSelector + ' [data-submit-action]')
        .on('click.coreUserTasks', formSelector + ' [data-submit-action]', function () {
            $(formSelector).find('[name="submit_action"]').val($(this).data('submit-action'));
        })
        .off('submit.coreUserTasks', '#user-tasks-document-number-settings-form')
        .on('submit.coreUserTasks', '#user-tasks-document-number-settings-form', function (event) {
            event.preventDefault();

            var $form = $(this);
            clearValidation($form);

            $.ajax({
                url: $form.attr('action'),
                type: 'PUT',
                headers: headers(),
                data: $form.serialize()
            }).done(function (response) {
                var data = response && response.data ? response.data : {};

                if (Object.prototype.hasOwnProperty.call(data, 'prefix')) {
                    $form.find('[name="prefix"]').val(data.prefix || '');
                }

                if (Object.prototype.hasOwnProperty.call(data, 'padding')) {
                    $form.find('[name="padding"]').val(data.padding);
                }

                showAlert($form.find('[data-form-alert]'), response && response.message ? response.message : message('settingsSaved'), 'success');
                showToast('success', response && response.message ? response.message : message('settingsSaved'));
            }).fail(function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    renderValidation($form, xhr.responseJSON.errors);
                    showAlert($form.find('[data-form-alert]'), message('validationFailed'), 'danger');
                    return;
                }

                showAlert($form.find('[data-form-alert]'), responseMessage(xhr), 'danger');
                showToast('error', responseMessage(xhr));
            });
        });

    initTable();
})(jQuery, window);
