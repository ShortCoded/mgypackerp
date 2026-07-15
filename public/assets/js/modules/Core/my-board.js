(function ($, window, document) {
    'use strict';

    const config = window.MyBoardConfig || {};
    const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';
    const state = {
        boards: config.boards || { task: [], note: [] },
        boardUser: config.boardUser || {},
        hiddenTaskLists: readHiddenTaskListsPreference(config.boardUser?.doc_num),
        currentItem: null,
        selectedItem: null,
        allTasksLoaded: false,
        allNotesLoaded: false,
        summernoteLoading: false,
        summernoteReady: Boolean($.fn.summernote),
    };

    const selectors = {
        container: '.js-board-container',
        itemModal: '#myBoardItemModal',
        itemForm: '#myBoardItemForm',
        detailsModal: '#myBoardDetailsModal',
        listModal: '#myBoardListModal',
        listForm: '#myBoardListForm',
        editor: '#board_description',
        commentForm: '.js-board-comment-form',
        commentEditor: '#board_comment_body',
        editCommentEditor: '#board_edit_comment_body',
        allTasksContainer: '.js-board-all-tasks',
        allNotesContainer: '.js-board-all-notes',
    };

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': csrfToken,
            Accept: 'application/json',
        },
    });

    const itemModal = bootstrap.Modal.getOrCreateInstance(document.querySelector(selectors.itemModal));
    const detailsModal = bootstrap.Modal.getOrCreateInstance(document.querySelector(selectors.detailsModal));
    const listModal = bootstrap.Modal.getOrCreateInstance(document.querySelector(selectors.listModal));

    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

    function urlFor(template, token, value) {
        return (template || '').replace(token, encodeURIComponent(value));
    }

    function normalizeCount(value, fallback) {
        const numeric = Number(value);

        if (Number.isFinite(numeric)) {
            return numeric;
        }

        const parsed = Number.parseInt(String(value || '').replace(/[^\d-]/g, ''), 10);

        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function hiddenTaskListsStorageKey(boardUserDocNum) {
        const actorDocNum = config.actor?.doc_num || 'user';
        const boardScope = boardUserDocNum || config.boardUser?.doc_num || 'board';
        const mode = config.mode || 'personal';

        return `my_board_hidden_task_lists:${actorDocNum}:${mode}:${boardScope}`;
    }

    function readHiddenTaskListsPreference(boardUserDocNum) {
        try {
            const value = window.localStorage?.getItem(hiddenTaskListsStorageKey(boardUserDocNum));
            const decoded = JSON.parse(value || '[]');

            return Array.isArray(decoded) ? decoded.filter(Boolean).map(String) : [];
        } catch (error) {
            return [];
        }
    }

    function writeHiddenTaskListsPreference() {
        try {
            window.localStorage?.setItem(
                hiddenTaskListsStorageKey(state.boardUser?.doc_num),
                JSON.stringify(state.hiddenTaskLists),
            );
        } catch (error) {
            // Ignore storage failures; the in-memory state still updates.
        }
    }

    function isTeamScreen() {
        return config.mode === 'team';
    }

    function boardUserParams() {
        return state.boardUser && state.boardUser.doc_num ? { board_user_doc_num: state.boardUser.doc_num } : {};
    }

    function canCreateForCurrentBoard() {
        return Boolean(config.can?.create) && (
            !state.boardUser?.doc_num
            || !config.actor?.doc_num
            || state.boardUser.doc_num === config.actor.doc_num
            || config.can?.assign
            || config.can?.manageAny
        );
    }

    function isTaskListHidden(docNum) {
        return state.hiddenTaskLists.includes(String(docNum || ''));
    }

    function setTaskListHidden(docNum, hidden) {
        const listDocNum = String(docNum || '');

        if (!listDocNum) {
            return;
        }

        state.hiddenTaskLists = hidden
            ? Array.from(new Set([...state.hiddenTaskLists, listDocNum]))
            : state.hiddenTaskLists.filter(function (hiddenDocNum) {
                return hiddenDocNum !== listDocNum;
            });

        writeHiddenTaskListsPreference();
        renderBoard('task');
    }

    function visibleItems(type, column, items) {
        if (type !== 'task' || !isTaskListHidden(column.doc_num)) {
            return items;
        }

        return [];
    }

    function showToast(icon, title) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
        }
    }

    function initTooltips($scope) {
        if (!window.bootstrap?.Tooltip) {
            return;
        }

        $scope.find('[data-bs-toggle="tooltip"]').each(function () {
            window.bootstrap.Tooltip.getOrCreateInstance(this);
        });
    }

    function summernoteIcons() {
        return {
            bold: 'fas fa-bold',
            italic: 'fas fa-italic',
            underline: 'fas fa-underline',
            eraser: 'fas fa-eraser',
            unorderedlist: 'fas fa-list-ul',
            orderedlist: 'fas fa-list-ol',
            alignLeft: 'fas fa-align-left',
            alignCenter: 'fas fa-align-center',
            alignRight: 'fas fa-align-right',
            alignJustify: 'fas fa-align-justify',
            outdent: 'fas fa-outdent',
            indent: 'fas fa-indent',
            link: 'fas fa-link',
            picture: 'fas fa-image',
            code: 'fas fa-code',
            caret: 'fas fa-caret-down',
            question: 'fas fa-question',
            close: 'fas fa-times',
            undo: 'fas fa-undo',
            redo: 'fas fa-redo',
        };
    }

    function loadSummernote() {
        if ($.fn.summernote || state.summernoteLoading) {
            state.summernoteReady = Boolean($.fn.summernote);
            return $.Deferred().resolve().promise();
        }

        state.summernoteReady = false;
        showToast('warning', config.messages?.summernoteMissing || 'Rich editor is unavailable.');

        return $.Deferred().resolve().promise();
    }

    function initRichEditor(selector, height) {
        const $editor = $(selector);

        if (!$editor.length || !state.summernoteReady || !$.fn.summernote || $editor.data('summernote')) {
            return;
        }

        $editor.summernote({
            height,
            direction: config.direction || 'ltr',
            dialogsInBody: true,
            tooltip: false,
            icons: summernoteIcons(),
            toolbar: [
                ['style', ['bold', 'italic', 'underline', 'clear']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['insert', ['link', 'picture']],
                ['view', ['codeview']],
            ],
            callbacks: {
                onChange: function () {
                    clearFieldError($editor);
                },
            },
        });
    }

    function initEditor() {
        initRichEditor(selectors.editor, 180);
    }

    function initCommentEditor() {
        initRichEditor(selectors.commentEditor, 120);
        initRichEditor(selectors.editCommentEditor, 120);
    }

    function setEditorValue(value) {
        const $editor = $(selectors.editor);

        if (state.summernoteReady && $.fn.summernote && $editor.data('summernote')) {
            $editor.summernote('code', value || '');
            return;
        }

        $editor.val(value || '');
    }

    function getEditorValue() {
        const $editor = $(selectors.editor);

        if (state.summernoteReady && $.fn.summernote && $editor.data('summernote')) {
            return $editor.summernote('code');
        }

        return $editor.val();
    }

    function setCommentEditorValue(value) {
        const $editor = $(selectors.commentEditor);

        if (!$editor.length) {
            return;
        }

        if (state.summernoteReady && $.fn.summernote && $editor.data('summernote')) {
            $editor.summernote('code', value || '');
            return;
        }

        $editor.val(value || '');
    }

    function getCommentEditorValue() {
        const $editor = $(selectors.commentEditor);

        if (state.summernoteReady && $.fn.summernote && $editor.data('summernote')) {
            return $editor.summernote('code');
        }

        return $editor.val();
    }

    function setEditCommentEditorValue(value) {
        const $editor = $(selectors.editCommentEditor);

        if (!$editor.length) {
            return;
        }

        if (state.summernoteReady && $.fn.summernote && $editor.data('summernote')) {
            $editor.summernote('code', value || '');
            return;
        }

        $editor.val(value || '');
    }

    function getEditCommentEditorValue() {
        const $editor = $(selectors.editCommentEditor);

        if (state.summernoteReady && $.fn.summernote && $editor.data('summernote')) {
            return $editor.summernote('code');
        }

        return $editor.val();
    }

    function clearErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $form.find('.js-board-errors, .js-board-list-errors, .js-board-comment-errors').addClass('d-none').empty();
    }

    function fieldNames($field) {
        const name = String($field.attr('name') || '');

        if (!name) {
            return [];
        }

        return [name, name.replace(/\[\]$/, '')];
    }

    function clearFieldError(field) {
        const $field = $(field);
        const $form = $field.closest('form');

        fieldNames($field).forEach(function (name) {
            $form.find(`[name="${name}"], [name="${name}[]"]`).removeClass('is-invalid');
            $form.find(`[data-error-for="${name}"]`).text('');
        });

        if ($form.find('.is-invalid').length === 0) {
            $form.find('.js-board-errors, .js-board-list-errors, .js-board-comment-errors').addClass('d-none').empty();
        }
    }

    function showErrors($form, xhr, fallbackSelector) {
        const errors = xhr.responseJSON?.errors || {};
        const message = xhr.responseJSON?.message || config.messages?.validationFailed || config.messages?.unexpectedError;

        Object.keys(errors).forEach(function (field) {
            const baseField = String(field).replace(/\.\d+$/, '');
            const $field = $form.find(`[name="${baseField}"], [name="${baseField}[]"]`);
            $field.addClass('is-invalid');
            $form.find(`[data-error-for="${baseField}"]`).text(errors[field][0] || '');
        });

        $form.find(fallbackSelector).removeClass('d-none').html(escapeHtml(message));
    }

    function boardLists(type) {
        return state.boards[type] || [];
    }

    function findBoardList(type, docNum, status) {
        return boardLists(type).find(function (list) {
            return (docNum && list.doc_num === docNum) || (!docNum && status && list.status === status);
        });
    }

    function setListItemCount(list, count) {
        if (!list) {
            return;
        }

        list.items_count = Math.max(0, count);
    }

    function adjustListItemCount(type, docNum, delta) {
        const list = findBoardList(type, docNum, null);

        if (!list) {
            return;
        }

        setListItemCount(list, normalizeCount(list.items_count, (list.items || []).length) + delta);
    }

    function orderItems(items, orderedDocNums) {
        if (!orderedDocNums.length) {
            return items;
        }

        return items.slice().sort(function (first, second) {
            const firstIndex = orderedDocNums.indexOf(first.doc_num);
            const secondIndex = orderedDocNums.indexOf(second.doc_num);

            if (firstIndex === -1 && secondIndex === -1) {
                return 0;
            }

            if (firstIndex === -1) {
                return 1;
            }

            if (secondIndex === -1) {
                return -1;
            }

            return firstIndex - secondIndex;
        });
    }

    function applyMovedItemToState(item, sourceListDocNum, targetListDocNum, orderedDocNums) {
        if (!item?.doc_num) {
            return;
        }

        const type = item.type || 'task';

        boardLists(type).forEach(function (list) {
            if (Array.isArray(list.items)) {
                list.items = list.items.filter(function (existingItem) {
                    return existingItem.doc_num !== item.doc_num;
                });
            }
        });

        if (sourceListDocNum && targetListDocNum && sourceListDocNum !== targetListDocNum) {
            adjustListItemCount(type, sourceListDocNum, -1);
            adjustListItemCount(type, targetListDocNum, 1);
        }

        const targetList = findBoardList(type, targetListDocNum || item.board_list_doc_num, item.status);

        if (targetList) {
            const targetItems = Array.isArray(targetList.items) ? targetList.items : [];
            targetList.items = orderItems([...targetItems, item], orderedDocNums);
        }
    }

    function removeListFromState(docNum) {
        let removedType = null;

        ['task', 'note'].forEach(function (type) {
            const currentLists = boardLists(type);
            const nextLists = currentLists.filter(function (list) {
                return list.doc_num !== docNum;
            });

            if (nextLists.length !== currentLists.length) {
                removedType = type;
                state.boards[type] = nextLists;
            }
        });

        return removedType;
    }

    function removeListColumn(docNum) {
        const $columns = $('.kanban-column').filter(function () {
            return $(this).data('board-list') === docNum;
        });
        const removedType = removeListFromState(docNum) || $columns.first().data('board-type');

        $columns.remove();
        fillAllListFilters();

        if ($(selectors.itemModal).hasClass('show') && $('#board_list_doc_num').val() === docNum) {
            fillListSelect($('#board_type').val() || removedType || activeType(), null);
        }
    }

    function listOptions(type, selectedDocNum) {
        return boardLists(type).map(function (column) {
            const selected = column.doc_num === selectedDocNum ? 'selected' : '';
            return `<option value="${escapeHtml(column.doc_num)}" data-status="${escapeHtml(column.status)}" ${selected}>${escapeHtml(column.name)}</option>`;
        }).join('');
    }

    function fillListSelect(type, selectedDocNum) {
        const $list = $('#board_list_doc_num');
        $list.html(listOptions(type, selectedDocNum));

        if (selectedDocNum) {
            $list.val(selectedDocNum);
        }

        syncStatusFromList();
    }

    function syncStatusFromList() {
        const status = $('#board_list_doc_num option:selected').data('status');

        if (status) {
            $('#board_status').val(status);
        }
    }

    function avatarHtml(user, sizeClass) {
        const initials = escapeHtml(user?.initials || 'U');
        const title = escapeHtml(user?.label || user?.name || '');
        const image = user?.avatar_url
            ? `<img class="rounded-circle" src="${escapeHtml(user.avatar_url)}" alt="${escapeHtml(user.name || user.label || '')}">`
            : `<div class="avatar-name rounded-circle bg-primary-subtle text-primary"><span>${initials}</span></div>`;

        return `<div class="avatar ${sizeClass || 'avatar-l'}" title="${title}" data-bs-toggle="tooltip" aria-label="${title}">${image}</div>`;
    }

    function userInitials(label) {
        return String(label || '')
            .trim()
            .split(/[\s/|]+/)
            .filter(Boolean)
            .slice(0, 2)
            .map(function (part) {
                return part.charAt(0);
            })
            .join('')
            .toUpperCase() || 'U';
    }

    function userIndicator(label) {
        if (!label) {
            return '';
        }

        const title = escapeHtml(label);
        const initials = escapeHtml(userInitials(label));

        return `<div class="avatar avatar-s my-board-card-user-indicator" title="${title}" data-bs-toggle="tooltip" aria-label="${title}">
            <div class="avatar-name rounded-circle bg-primary-subtle text-primary"><span>${initials}</span></div>
        </div>`;
    }

    function avatarStack(users) {
        const items = users || [];

        if (!items.length) {
            return '';
        }

        const visible = items.slice(0, 3).map(function (user) {
            return avatarHtml(user, 'avatar-s');
        }).join('');
        const hiddenUsersTitle = escapeHtml(items.slice(3).map(function (user) {
            return user?.label || user?.name || '';
        }).filter(Boolean).join(', '));
        const extra = items.length > 3
            ? `<span class="badge rounded-pill badge-subtle-secondary ms-1" title="${hiddenUsersTitle}" data-bs-toggle="tooltip">+${items.length - 3}</span>`
            : '';

        return `<div class="d-flex align-items-center my-board-avatar-stack my-board-card-avatars">${visible}${extra}</div>`;
    }

    function cardHtml(item) {
        const color = item.color || 'primary';
        const priority = item.priority_class || 'info';
        const description = item.description_text ? `<p class="my-board-card-description mb-0">${escapeHtml(item.description_text)}</p>` : '';
        const due = item.due_at ? `<span class="badge badge-subtle-warning"><span class="far fa-clock me-1"></span>${escapeHtml(item.due_at)}</span>` : '';
        const assignee = !item.assignees?.length ? userIndicator(item.assigned_to_label) : '';
        const avatars = avatarStack(item.assignees || []);
        const locked = item.can_move ? '' : ' data-board-locked="1"';
        const activityBadges = [
            Number(item.comments_count || 0) > 0 ? `<span class="badge badge-subtle-secondary"><span class="far fa-comment me-1"></span>${Number(item.comments_count || 0)}</span>` : '',
            Number(item.views_count || 0) > 0 ? `<span class="badge badge-subtle-secondary"><span class="far fa-eye me-1"></span>${Number(item.views_count || 0)}</span>` : '',
        ].filter(Boolean).join('');
        const priorityBadge = item.type === 'task'
            ? `<span class="badge badge-subtle-${escapeHtml(priority)}">${escapeHtml(item.priority_label)}</span>`
            : '';
        const typeBadge = `<span class="badge badge-subtle-secondary">${escapeHtml(item.type_label)}</span>`;
        const metaBadges = [due, activityBadges].filter(Boolean).join('');
        const people = avatars || assignee
            ? `<div class="d-flex align-items-center justify-content-end gap-1 min-w-0 ms-auto">${avatars}${assignee}</div>`
            : '';
        const footer = metaBadges || people
            ? `<div class="d-flex align-items-center justify-content-between my-board-card-footer">
                    <div class="d-flex align-items-center flex-wrap my-board-card-meta">${metaBadges}</div>
                    ${people}
                </div>`
            : '';
        const actions = item.can_edit || item.can_delete
            ? `<div class="dropdown my-board-card-actions ms-2">
                    <button class="btn btn-link btn-sm p-0 text-600" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="fas fa-ellipsis-h"></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        ${item.can_edit ? `<button class="dropdown-item js-board-edit" type="button" data-doc-num="${escapeHtml(item.doc_num)}">${escapeHtml(config.text?.edit || 'Edit')}</button>` : ''}
                        ${item.can_delete ? `<button class="dropdown-item text-danger js-board-delete-item" type="button" data-doc-num="${escapeHtml(item.doc_num)}">${escapeHtml(config.text?.delete || 'Delete')}</button>` : ''}
                    </div>
                </div>`
            : '';

        return `<div class="card kanban-item my-board-card mb-2 border-${escapeHtml(color)}" data-doc-num="${escapeHtml(item.doc_num)}"${locked}>
            <div class="card-body">
                <div class="d-flex align-items-start">
                    <div class="min-w-0 flex-1">
                        <h6 class="my-board-card-title text-900">${escapeHtml(item.title)}</h6>
                        ${description}
                    </div>
                    ${actions}
                </div>
                <div class="d-flex align-items-center flex-wrap my-board-card-badges">${priorityBadge}${typeBadge}</div>
                ${footer}
            </div>
        </div>`;
    }

    function columnHtml(column) {
        const type = column.type;
        const hasRenderedItems = Array.isArray(column.items);
        const rawItems = hasRenderedItems ? column.items : [];
        const isHiddenTaskList = type === 'task' && isTaskListHidden(column.doc_num);
        const items = visibleItems(type, column, rawItems);
        const count = hasRenderedItems ? items.length : normalizeCount(column.items_count, 0);
        const deleteItemCount = normalizeCount(column.items_count, rawItems.length);
        const addLabel = type === 'note' ? config.text?.addNote : config.text?.addTask;
        const emptyLabel = type === 'note' ? config.messages?.emptyNote : config.messages?.emptyTask;
        const hiddenLabel = config.messages?.hiddenTaskList || '';
        const addButton = canCreateForCurrentBoard()
            ? `<div class="kanban-column-footer">
                    <button class="btn btn-link btn-sm d-block w-100 btn-add-card text-decoration-none text-600 js-board-add" type="button" data-board-type="${escapeHtml(type)}" data-board-list="${escapeHtml(column.doc_num)}">
                        <span class="fas fa-plus me-2"></span>${escapeHtml(addLabel)}
                    </button>
                </div>`
            : '';
        const deleteLabel = escapeHtml(config.text?.deleteList || 'Delete List');
        const blockedTooltip = escapeHtml(config.messages?.listDeleteBlockedTooltip || config.messages?.listDeleteBlocked || '');
        const canDeleteList = Boolean(config.can?.listDelete);
        const listDeleteMenuItem = canDeleteList
            ? (deleteItemCount === 0
                ? `<button class="dropdown-item text-danger js-board-delete-list" type="button" data-list="${escapeHtml(column.doc_num)}">${deleteLabel}</button>`
                : `<span class="dropdown-item disabled text-600" data-bs-toggle="tooltip" title="${blockedTooltip}" aria-disabled="true">${deleteLabel}</span>`)
            : '';
        const listVisibilityMenuItem = type === 'task'
            ? `<button class="dropdown-item js-board-toggle-list-tasks" type="button" data-list="${escapeHtml(column.doc_num)}">
                    <span class="far ${isHiddenTaskList ? 'fa-eye' : 'fa-eye-slash'} me-2"></span>${escapeHtml(isHiddenTaskList ? config.text?.showListTasks : config.text?.hideListTasks)}
                </button>`
            : '';
        const listActions = config.can?.listEdit || listVisibilityMenuItem || canDeleteList
            ? `<div class="dropdown">
                    <button class="btn btn-link btn-sm p-0 text-600" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="fas fa-ellipsis-h"></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        ${config.can?.listEdit ? `<button class="dropdown-item js-board-edit-list" type="button" data-list="${escapeHtml(column.doc_num)}">${escapeHtml(config.text?.edit || 'Edit')}</button>` : ''}
                        ${listVisibilityMenuItem}
                        ${listDeleteMenuItem}
                    </div>
                </div>`
            : '';
        const headerActions = listActions
            ? `<div class="d-flex align-items-center gap-2">${listActions}</div>`
            : '';
        const itemHtml = isHiddenTaskList
            ? `<div class="my-board-empty rounded-2 p-3 text-center text-600 fs-10" data-empty-state="1">
                    <div>${escapeHtml(hiddenLabel)}</div>
                    <button class="btn btn-link btn-sm p-0 mt-1 js-board-show-list-tasks" type="button" data-list="${escapeHtml(column.doc_num)}">
                        <span class="far fa-eye me-1"></span>${escapeHtml(config.text?.showListTasks || '')}
                    </button>
                </div>`
            : (count > 0
                ? items.map(cardHtml).join('')
                : `<div class="my-board-empty rounded-2 p-3 text-center text-600 fs-10" data-empty-state="1">${escapeHtml(emptyLabel)}</div>`);

        return `<div class="kanban-column" data-board-list="${escapeHtml(column.doc_num)}" data-status="${escapeHtml(column.status)}" data-board-type="${escapeHtml(type)}">
            <div class="kanban-column-header">
                <div class="d-flex align-items-center justify-content-between">
                    <h5 class="fs-9 mb-0">
                        <span class="badge badge-subtle-${escapeHtml(column.color || 'primary')} me-2">&nbsp;</span>
                        ${escapeHtml(column.name)}
                        <span class="text-500 js-board-column-count">(${count})</span>
                    </h5>
                    ${headerActions}
                </div>
            </div>
            <div class="kanban-items-container scrollbar js-board-column-items" data-board-list="${escapeHtml(column.doc_num)}" data-status="${escapeHtml(column.status)}" data-board-type="${escapeHtml(type)}" data-sortable="data-sortable">${itemHtml}</div>
            ${addButton}
        </div>`;
    }

    function renderBoard(type) {
        const $container = $(`${selectors.container}[data-board-type="${type}"]`);
        const columns = boardLists(type);

        if (!columns.length) {
            $container.html(`<div class="alert alert-subtle-warning m-3">${escapeHtml(config.messages?.unexpectedError || '')}</div>`);
            return;
        }

        $container.html(columns.map(columnHtml).join(''));
        initTooltips($container);
        initSortables($container);
    }

    function renderLoadingState() {
        $(selectors.container).html('<div class="text-center text-600 py-5"><span class="fas fa-circle-notch fa-spin me-2"></span></div>');
    }

    function fillAllListFilter(selector, type) {
        const $select = $(selector);

        if (!$select.length) {
            return;
        }

        const selected = $select.val();
        const options = [`<option value="">${escapeHtml(config.text?.all || 'All')}</option>`];

        boardLists(type).forEach(function (column) {
            options.push(`<option value="${escapeHtml(column.doc_num)}">${escapeHtml(column.name)}</option>`);
        });

        $select.html(options.join(''));
        $select.val(selected || '');
    }

    function fillAllListFilters() {
        fillAllListFilter('.js-board-all-tasks-list-filter', 'task');
        fillAllListFilter('.js-board-all-notes-list-filter', 'note');
    }

    function loadBoard() {
        $('.js-board-refresh .fas').addClass('fa-spin');
        renderLoadingState();

        return $.getJSON(config.urls?.data, boardUserParams())
            .done(function (response) {
                state.boards = response.data?.boards || { task: [], note: [] };
                state.boardUser = response.data?.board_user || state.boardUser;
                state.hiddenTaskLists = readHiddenTaskListsPreference(state.boardUser?.doc_num);
                renderBoard('task');
                renderBoard('note');
                fillAllListFilters();
                loadActiveTeamBoard();
            })
            .fail(function () {
                showToast('error', config.messages?.unexpectedError || 'Unexpected error');
            })
            .always(function () {
                $('.js-board-refresh .fas').removeClass('fa-spin');
            });
    }

    function refreshWorkSurface() {
        if (isTeamScreen()) {
            loadActiveTeamBoard();
            return;
        }

        loadBoard();
    }

    function initSortables($scope) {
        if (!window.Sortable || !config.can?.reorder) {
            return;
        }

        $scope.find('.js-board-column-items').each(function () {
            const element = this;

            if ($(element).data('sortable-instance')) {
                return;
            }

            const sortable = Sortable.create(element, {
                group: `my-board-${$(element).data('board-type')}`,
                animation: 150,
                filter: '[data-board-locked="1"], [data-empty-state="1"], .dropdown, .dropdown *',
                preventOnFilter: false,
                onEnd: function (event) {
                    persistMove(event);
                },
            });

            $(element).data('sortable-instance', sortable);
        });
    }

    function persistMove(event) {
        const $item = $(event.item);
        const docNum = $item.data('doc-num');
        const $target = $(event.to);
        const $source = $(event.from);
        const ordered = $target.children('.kanban-item').map(function () {
            return $(this).data('doc-num');
        }).get();

        $.ajax({
            url: urlFor(config.urls?.move, '__TASK__', docNum),
            method: 'PATCH',
            data: {
                board_list_doc_num: $target.data('board-list'),
                status: $target.data('status'),
                ordered_doc_nums: ordered,
            },
        }).done(function (response) {
            const movedItem = response.data?.item || null;

            showToast('success', response.message || config.messages?.boardUpdated);

            if (!movedItem) {
                loadBoard();
                return;
            }

            applyMovedItemToState(
                movedItem,
                $source.data('board-list'),
                $target.data('board-list') || movedItem.board_list_doc_num,
                ordered,
            );
            renderBoard(movedItem.type || $target.data('board-type') || 'task');
        }).fail(function () {
            showToast('error', config.messages?.unexpectedError);
            loadBoard();
        });
    }

    function resetItemForm(type, listDocNum) {
        const $form = $(selectors.itemForm);
        clearErrors($form);
        $form[0].reset();
        $form.find('[name="doc_num"]').val('');
        $form.find('[name="board_user_doc_num"]').val(state.boardUser.doc_num || '');
        $('#board_type').val(type || 'task').prop('disabled', false);
        fillListSelect(type || 'task', listDocNum || null);
        setItemActionLabels(type || 'task');
        $('#board_priority').val('normal');
        $('#board_color').val('');
        setEditorValue('');
        setEditCommentEditorValue('');
        hydrateAssignedSelect([]);
        toggleTaskFields(type || 'task');
        $('.js-board-edit-comments-section').addClass('d-none');
        $('.js-board-edit-comments-list').empty();
        $('.js-board-edit-comment-errors').addClass('d-none').empty();
        $('.js-board-delete').addClass('d-none').removeData('doc-num');
        state.currentItem = null;
    }

    function toggleTaskFields(type) {
        const isTask = type === 'task';
        $('.js-board-task-field').toggleClass('d-none', !isTask);
        $('#board_priority').prop('disabled', !isTask).prop('required', isTask);
        $('#board_due_at').prop('disabled', !isTask);
        $('#board_assignee_doc_nums').prop('disabled', !isTask);
    }

    function setItemActionLabels(type) {
        const isNote = type === 'note';
        const saveLabel = isNote
            ? (config.text?.saveNote || config.text?.save)
            : (config.text?.saveTask || config.text?.save);
        const deleteLabel = isNote
            ? (config.text?.deleteNote || config.text?.delete)
            : (config.text?.deleteTask || config.text?.delete);

        $('.js-board-save').text(saveLabel || '');
        $('.js-board-delete-label').text(deleteLabel || '');
    }

    function openCreateModal(type, listDocNum) {
        if (!canCreateForCurrentBoard()) {
            return;
        }

        resetItemForm(type, listDocNum);
        $('.js-board-modal-title').text(type === 'note' ? config.text?.addNote : config.text?.addTask);
        loadSummernote().always(initEditor);
        itemModal.show();
    }

    function hydrateAssignedSelect(assignees) {
        const $select = $('#board_assignee_doc_nums');

        if (!$select.length) {
            return;
        }

        $select.empty();

        (assignees || []).forEach(function (assignee) {
            const docNum = assignee.doc_num || assignee.id || assignee;
            const label = assignee.label || assignee.text || docNum;

            if (docNum) {
                $select.append(new Option(label, docNum, true, true));
            }
        });

        $select.trigger('change');
    }

    function openEditModal(item) {
        resetItemForm(item.type, item.board_list_doc_num);
        state.currentItem = item;
        state.selectedItem = item;
        $('.js-board-modal-title').text(config.text?.editTitle);
        $('#board_type').val(item.type).prop('disabled', true);
        $('#board_title').val(item.title);
        $('#board_status').val(item.status);
        $('#board_priority').val(item.priority || 'normal');
        $('#board_due_at').val(item.due_at || '');
        $('#board_color').val(item.color || '');
        fillListSelect(item.type, item.board_list_doc_num);
        setEditorValue(item.description || '');
        hydrateAssignedSelect(item.assignees || []);
        toggleTaskFields(item.type);
        renderEditComments(item);

        if (item.can_delete) {
            $('.js-board-delete').removeClass('d-none').data('doc-num', item.doc_num);
        }

        loadSummernote().always(function () {
            initEditor();
            initCommentEditor();
            setEditorValue(item.description || '');
        });
        itemModal.show();
    }

    function renderEditComments(item) {
        const comments = item?.comments || [];

        $('.js-board-edit-comments-section').toggleClass('d-none', !item?.doc_num);
        $('.js-board-edit-comments-list').html(commentsHtml(comments));
        setEditCommentEditorValue('');
    }

    function fetchItem(docNum, callback) {
        $.getJSON(urlFor(config.urls?.show, '__TASK__', docNum))
            .done(function (response) {
                callback(response.data?.item || null);
            })
            .fail(function () {
                showToast('error', config.messages?.unexpectedError);
            });
    }

    function openDetailsModal(item) {
        state.selectedItem = item;
        $('.js-board-details-title').text(item.title);
        $('.js-board-details-meta').text(`${item.type_label} · ${item.board_list_label || item.status_label}`);
        $('.js-board-details-description').html(item.description || `<span class="text-600">${escapeHtml(config.messages?.emptyNote || '')}</span>`);
        $('.js-board-details-grid').html(detailsGridHtml(item));
        $('.js-board-details-view-count')
            .text((item.viewers || []).length)
            .toggleClass('d-none', !(item.viewers || []).length);
        $('.js-board-details-viewers').html(viewersHtml(item.viewers || []));
        $('.js-board-comments-list').html(commentsHtml(item.comments || []));
        $('.js-board-details-edit').toggleClass('d-none', !item.can_edit).data('doc-num', item.doc_num);
        $('.js-board-details-delete').toggleClass('d-none', !item.can_delete).data('doc-num', item.doc_num);
        clearErrors($(selectors.commentForm));
        setCommentEditorValue('');
        loadSummernote().always(initCommentEditor);
        detailsModal.show();
    }

    function viewersHtml(viewers) {
        if (!viewers.length) {
            return `<div class="text-600 fs-10">${escapeHtml(config.messages?.emptyViewers || '')}</div>`;
        }

        return viewers.map(function (viewer) {
            return `<div class="d-flex align-items-center gap-2 border rounded-2 px-2 py-1">
                ${avatarHtml(viewer, 'avatar-l')}
                <div>
                    <div class="fw-semibold fs-10">${escapeHtml(viewer.label || viewer.name || '')}</div>
                    <div class="text-600 fs-11">${escapeHtml(viewer.viewed_at || '')}</div>
                </div>
            </div>`;
        }).join('');
    }

    function commentsHtml(comments) {
        if (!comments.length) {
            return `<div class="text-600 fs-10">${escapeHtml(config.messages?.emptyComments || '')}</div>`;
        }

        return comments.map(function (comment) {
            const user = comment.user || {};
            const deleteButton = comment.can_delete
                ? `<button class="btn btn-link btn-sm p-0 text-danger js-board-delete-comment" type="button" data-comment-id="${escapeHtml(comment.id)}">${escapeHtml(config.text?.deleteComment || 'Delete')}</button>`
                : '';

            return `<div class="my-board-comment ps-3 py-2 mb-2">
                <div class="d-flex align-items-start">
                    ${avatarHtml(user, 'avatar-l')}
                    <div class="ms-2 flex-1 min-w-0">
                        <div class="d-flex justify-content-between gap-2">
                            <div>
                                <div class="fw-semibold fs-10">${escapeHtml(user.label || user.name || '')}</div>
                                <div class="text-600 fs-11">${escapeHtml(comment.created_at || '')}</div>
                            </div>
                            ${deleteButton}
                        </div>
                        <div class="my-board-rich-content text-800 mt-2">${comment.body_html || ''}</div>
                    </div>
                </div>
            </div>`;
        }).join('');
    }

    function detailsGridHtml(item) {
        const rows = [
            [config.text?.viewTitle || 'Details', item.doc_num],
            [$('#board_list_doc_num').closest('.col-md-4').find('label').text() || 'List', item.board_list_label],
            item.type === 'task' ? [$('label[for="board_priority"]').text() || 'Priority', item.priority_label] : null,
            [config.text?.boardOwner || 'Board Owner', item.board_owner_label],
            [$('label[for="board_assignee_doc_nums"]').text() || config.text?.assignees || 'Assignees', item.assigned_to_label],
            [$('label[for="board_due_at"]').text() || 'Due date', item.due_at],
            [config.text?.creator || 'Created by', item.created_by_label],
            [config.text?.createdAt || 'Created', item.created_at],
            [config.text?.updatedAt || 'Updated', item.updated_at],
        ].filter(function (row) {
            return row && row[1];
        });

        return rows.map(function (row) {
            return `<div class="col-md-6">
                <div class="border rounded-2 p-2 h-100">
                    <div class="fs-11 text-600">${escapeHtml(row[0])}</div>
                    <div class="fw-semibold">${escapeHtml(row[1])}</div>
                </div>
            </div>`;
        }).join('');
    }

    function submitItemForm(event) {
        event.preventDefault();
        const $form = $(selectors.itemForm);
        clearErrors($form);

        const docNum = $form.find('[name="doc_num"]').val() || state.currentItem?.doc_num;
        const method = docNum ? 'PUT' : 'POST';
        const url = docNum ? urlFor(config.urls?.update, '__TASK__', docNum) : config.urls?.store;
        const data = $form.serializeArray().reduce(function (carry, field) {
            if (field.name.endsWith('[]')) {
                const name = field.name.replace(/\[\]$/, '');
                carry[name] = carry[name] || [];
                carry[name].push(field.value);

                return carry;
            }

            carry[field.name] = field.value;
            return carry;
        }, {});

        data.description = getEditorValue();
        data.type = $('#board_type').val();
        data.status = $('#board_status').val();

        $.ajax({ url, method, data })
            .done(function (response) {
                showToast('success', response.message || config.messages?.created);
                itemModal.hide();
                refreshWorkSurface();
            })
            .fail(function (xhr) {
                showErrors($form, xhr, '.js-board-errors');
            });
    }

    function deleteItem(docNum) {
        const runDelete = function () {
            return $.ajax({
                url: urlFor(config.urls?.destroy, '__TASK__', docNum),
                method: 'DELETE',
            }).done(function (response) {
                showToast('success', response.message || config.messages?.deleted);
                itemModal.hide();
                detailsModal.hide();
                refreshWorkSurface();
            }).fail(function () {
                showToast('error', config.messages?.unexpectedError);
            });
        };

        if (!window.Swal) {
            runDelete();
            return;
        }

        Swal.fire({
            title: config.messages?.deleteConfirmTitle,
            text: config.messages?.deleteConfirmText,
            icon: 'warning',
            showCloseButton: true,
            showCancelButton: true,
            focusCancel: true,
            allowEscapeKey: true,
            confirmButtonText: config.messages?.deleteConfirmYes,
            cancelButtonText: config.text?.cancel,
            heightAuto: false,
        }).then(function (result) {
            if (result.isConfirmed) {
                runDelete();
            }
        });
    }

    function submitComment(event) {
        event.preventDefault();

        if (!state.selectedItem?.doc_num) {
            return;
        }

        const $form = $(selectors.commentForm);
        clearErrors($form);

        $.ajax({
            url: urlFor(config.urls?.commentStore, '__TASK__', state.selectedItem.doc_num),
            method: 'POST',
            data: {
                body_html: getCommentEditorValue(),
            },
        }).done(function (response) {
            showToast('success', response.message || config.messages?.commentCreated);
            setCommentEditorValue('');
            fetchItem(state.selectedItem.doc_num, function (item) {
                if (item) {
                    openDetailsModal(item);
                    refreshWorkSurface();
                }
            });
        }).fail(function (xhr) {
            showErrors($form, xhr, '.js-board-comment-errors');
        });
    }

    function submitEditComment() {
        if (!state.currentItem?.doc_num) {
            return;
        }

        const $section = $('.js-board-edit-comments-section');
        $section.find('.js-board-edit-comment-errors').addClass('d-none').empty();
        $section.find('[data-error-for="body_html"]').text('');
        $(selectors.editCommentEditor).removeClass('is-invalid');

        $.ajax({
            url: urlFor(config.urls?.commentStore, '__TASK__', state.currentItem.doc_num),
            method: 'POST',
            data: {
                body_html: getEditCommentEditorValue(),
            },
        }).done(function (response) {
            showToast('success', response.message || config.messages?.commentCreated);
            setEditCommentEditorValue('');
            fetchItem(state.currentItem.doc_num, function (item) {
                if (item) {
                    state.currentItem = item;
                    state.selectedItem = item;
                    renderEditComments(item);
                    refreshWorkSurface();
                }
            });
        }).fail(function (xhr) {
            const message = xhr.responseJSON?.message || config.messages?.validationFailed || config.messages?.unexpectedError;
            const error = xhr.responseJSON?.errors?.body_html?.[0] || message;

            $(selectors.editCommentEditor).addClass('is-invalid');
            $section.find('[data-error-for="body_html"]').text(error);
            $section.find('.js-board-edit-comment-errors').removeClass('d-none').html(escapeHtml(message));
        });
    }

    function deleteComment(commentId) {
        const item = state.selectedItem || state.currentItem;

        if (!item?.doc_num || !commentId) {
            return;
        }

        const docNum = item.doc_num;

        $.ajax({
            url: urlFor(
                urlFor(config.urls?.commentDestroy, '__TASK__', docNum),
                '__COMMENT__',
                commentId,
            ),
            method: 'DELETE',
        }).done(function (response) {
            showToast('success', response.message || config.messages?.commentDeleted);
            fetchItem(docNum, function (freshItem) {
                if (freshItem) {
                    if ($(selectors.detailsModal).hasClass('show')) {
                        openDetailsModal(freshItem);
                    }

                    if ($(selectors.itemModal).hasClass('show')) {
                        state.currentItem = freshItem;
                        state.selectedItem = freshItem;
                        renderEditComments(freshItem);
                    }

                    refreshWorkSurface();
                }
            });
        }).fail(function () {
            showToast('error', config.messages?.unexpectedError);
        });
    }

    function openListModal(type, listDocNum) {
        const $form = $(selectors.listForm);
        clearErrors($form);
        $form[0].reset();
        $form.find('[name="doc_num"]').val('');
        $('#board_list_type').val(type || activeType()).prop('disabled', false);
        $('#board_list_status').val('todo');
        $('#board_list_color').val('primary');

        if (listDocNum) {
            const list = findList(listDocNum);
            if (list) {
                $form.find('[name="doc_num"]').val(list.doc_num);
                $('#board_list_type').val(list.type).prop('disabled', true);
                $('#board_list_status').val(list.status);
                $('#board_list_name').val(list.raw_name || list.name);
                $('#board_list_color').val(list.color || 'primary');
            }
        }

        listModal.show();
    }

    function submitListForm(event) {
        event.preventDefault();
        const $form = $(selectors.listForm);
        clearErrors($form);
        const docNum = $form.find('[name="doc_num"]').val();
        const method = docNum ? 'PUT' : 'POST';
        const url = docNum ? urlFor(config.urls?.listUpdate, '__LIST__', docNum) : config.urls?.listStore;
        const data = $form.serializeArray().reduce(function (carry, field) {
            carry[field.name] = field.value;
            return carry;
        }, {});

        data.type = $('#board_list_type').val();

        $.ajax({ url, method, data })
            .done(function (response) {
                showToast('success', response.message || config.messages?.boardUpdated);
                listModal.hide();
                loadBoard();
            })
            .fail(function (xhr) {
                showErrors($form, xhr, '.js-board-list-errors');
            });
    }

    function deleteList(docNum) {
        const runDelete = function () {
            $.ajax({
                url: urlFor(config.urls?.listDestroy, '__LIST__', docNum),
                method: 'DELETE',
            }).done(function (response) {
                showToast('success', response.message || config.messages?.listDeleted);
                removeListColumn(docNum);
            }).fail(function (xhr) {
                showToast('error', xhr.responseJSON?.message || config.messages?.unexpectedError);
            });
        };

        if (!window.Swal) {
            runDelete();
            return;
        }

        Swal.fire({
            title: config.messages?.listDeleteConfirmTitle || config.messages?.deleteConfirmTitle,
            text: config.messages?.listDeleteConfirmText || config.messages?.deleteConfirmText,
            icon: 'warning',
            showCloseButton: true,
            showCancelButton: true,
            focusCancel: true,
            allowEscapeKey: true,
            confirmButtonText: config.messages?.deleteConfirmYes,
            cancelButtonText: config.text?.cancel,
            heightAuto: false,
        }).then(function (result) {
            if (result.isConfirmed) {
                runDelete();
            }
        });
    }

    function findList(docNum) {
        return ['task', 'note'].flatMap(boardLists).find(function (list) {
            return list.doc_num === docNum;
        });
    }

    function teamFilterParams(selector) {
        const params = {};

        $(selector).each(function () {
            const $field = $(this);
            const name = $field.attr('name');
            const value = $field.val();

            if (name && value) {
                params[name] = value;
            }
        });

        return params;
    }

    function showTeamContainer(type) {
        $(selectors.allTasksContainer).toggleClass('d-none', type !== 'task');
        $(selectors.allNotesContainer).toggleClass('d-none', type !== 'note');
    }

    function loadActiveTeamBoard() {
        if (!isTeamScreen()) {
            return;
        }

        if ($('#team-all-notes-pane').hasClass('active') && config.can?.viewAllNotes) {
            loadAllNotes();
            return;
        }

        if (config.can?.viewAllTasks) {
            loadAllTasks();
        } else if (config.can?.viewAllNotes) {
            loadAllNotes();
        }
    }

    function loadAllTasks() {
        if (!config.can?.viewAllTasks || !$(selectors.allTasksContainer).length) {
            return;
        }

        showTeamContainer('task');
        $(selectors.allTasksContainer).html('<div class="text-center text-600 py-5"><span class="fas fa-circle-notch fa-spin me-2"></span></div>');
        $('.js-board-all-tasks-refresh .fas').addClass('fa-spin');

        $.getJSON(config.urls?.allTasks, teamFilterParams('.js-board-all-tasks-filter'))
            .done(function (response) {
                state.allTasksLoaded = true;
                renderAllTasks(response.data?.items || []);
            })
            .fail(function () {
                showToast('error', config.messages?.unexpectedError);
            })
            .always(function () {
                $('.js-board-all-tasks-refresh .fas').removeClass('fa-spin');
            });
    }

    function renderAllTasks(items) {
        if (!items.length) {
            $(selectors.allTasksContainer).html(`<div class="my-board-empty rounded-2 p-4 text-center text-600">${escapeHtml(config.messages?.emptyAllTasks || '')}</div>`);
            return;
        }

        const rows = items.map(function (item) {
            return `<tr>
                <td class="align-middle">
                    <button class="btn btn-link p-0 fw-semibold text-start js-board-open-task" type="button" data-doc-num="${escapeHtml(item.doc_num)}">${escapeHtml(item.title)}</button>
                    <div class="fs-11 text-600">${escapeHtml(item.doc_num || '')}</div>
                </td>
                <td class="align-middle">${escapeHtml(item.board_owner_label || '')}</td>
                <td class="align-middle">
                    ${avatarStack(item.assignees || [])}
                    <div class="fs-11 text-600">${escapeHtml(item.assigned_to_label || '')}</div>
                </td>
                <td class="align-middle">${escapeHtml(item.created_by_label || '')}</td>
                <td class="align-middle">${escapeHtml(item.board_list_label || item.status_label || '')}</td>
                <td class="align-middle"><span class="badge badge-subtle-${escapeHtml(item.priority_class || 'info')}">${escapeHtml(item.priority_label || '')}</span></td>
                <td class="align-middle text-end text-nowrap">
                    <span class="badge badge-subtle-secondary me-1"><span class="far fa-eye me-1"></span>${Number(item.views_count || 0)}</span>
                    <span class="badge badge-subtle-secondary"><span class="far fa-comment me-1"></span>${Number(item.comments_count || 0)}</span>
                </td>
                <td class="align-middle text-nowrap">${escapeHtml(item.created_at || '')}</td>
                <td class="align-middle text-nowrap">${escapeHtml(item.updated_at || '')}</td>
                <td class="align-middle text-nowrap">${escapeHtml(item.due_at || '')}</td>
            </tr>`;
        }).join('');

        $(selectors.allTasksContainer).html(`<div class="card">
            <div class="table-responsive scrollbar">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="bg-100 text-700">
                        <tr>
                            <th>${escapeHtml($('#board_title').closest('.col-12').find('label').text() || 'Title')}</th>
                            <th>${escapeHtml(config.text?.boardOwner || 'Board Owner')}</th>
                            <th>${escapeHtml(config.text?.assignees || 'Assignees')}</th>
                            <th>${escapeHtml(config.text?.creator || 'Creator')}</th>
                            <th>${escapeHtml($('#board_list_doc_num').closest('.col-md-4').find('label').text() || 'List')}</th>
                            <th>${escapeHtml($('label[for="board_priority"]').text() || 'Priority')}</th>
                            <th class="text-end">${escapeHtml(`${config.text?.viewers || 'Views'} / ${config.text?.comments || 'Comments'}`)}</th>
                            <th>${escapeHtml(config.text?.createdAt || 'Created')}</th>
                            <th>${escapeHtml(config.text?.updatedAt || 'Updated')}</th>
                            <th>${escapeHtml($('label[for="board_due_at"]').text() || 'Due date')}</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        </div>`);
    }

    function loadAllNotes() {
        if (!config.can?.viewAllNotes || !$(selectors.allNotesContainer).length) {
            return;
        }

        showTeamContainer('note');
        $(selectors.allNotesContainer).html('<div class="text-center text-600 py-5"><span class="fas fa-circle-notch fa-spin me-2"></span></div>');
        $('.js-board-all-notes-refresh .fas').addClass('fa-spin');

        $.getJSON(config.urls?.allNotes, teamFilterParams('.js-board-all-notes-filter'))
            .done(function (response) {
                state.allNotesLoaded = true;
                renderAllNotes(response.data?.items || []);
            })
            .fail(function () {
                showToast('error', config.messages?.unexpectedError);
            })
            .always(function () {
                $('.js-board-all-notes-refresh .fas').removeClass('fa-spin');
            });
    }

    function renderAllNotes(items) {
        if (!items.length) {
            $(selectors.allNotesContainer).html(`<div class="my-board-empty rounded-2 p-4 text-center text-600">${escapeHtml(config.messages?.emptyAllNotes || '')}</div>`);
            return;
        }

        const rows = items.map(function (item) {
            return `<tr>
                <td class="align-middle">
                    <button class="btn btn-link p-0 fw-semibold text-start js-board-open-task" type="button" data-doc-num="${escapeHtml(item.doc_num)}">${escapeHtml(item.title)}</button>
                    <div class="fs-11 text-600">${escapeHtml(item.description_text || item.doc_num || '')}</div>
                </td>
                <td class="align-middle">${escapeHtml(item.board_owner_label || '')}</td>
                <td class="align-middle">${escapeHtml(item.created_by_label || '')}</td>
                <td class="align-middle">${escapeHtml(item.board_list_label || item.status_label || '')}</td>
                <td class="align-middle text-nowrap">${escapeHtml(item.created_at || '')}</td>
                <td class="align-middle text-nowrap">${escapeHtml(item.updated_at || '')}</td>
            </tr>`;
        }).join('');

        $(selectors.allNotesContainer).html(`<div class="card">
            <div class="table-responsive scrollbar">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="bg-100 text-700">
                        <tr>
                            <th>${escapeHtml(config.text?.allNotes || 'All Notes')}</th>
                            <th>${escapeHtml(config.text?.boardOwner || 'Board Owner')}</th>
                            <th>${escapeHtml(config.text?.creator || 'Creator')}</th>
                            <th>${escapeHtml($('#board_list_doc_num').closest('.col-md-4').find('label').text() || 'List')}</th>
                            <th>${escapeHtml(config.text?.createdAt || 'Created')}</th>
                            <th>${escapeHtml(config.text?.updatedAt || 'Updated')}</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        </div>`);
    }

    function activeType() {
        return $('#notes-board-pane').hasClass('active') ? 'note' : 'task';
    }

    function shouldIgnoreShortcut(event) {
        const target = event.target;
        const tagName = (target.tagName || '').toLowerCase();
        const isEditor = $(target).closest('.note-editor, .select2-container').length > 0;

        return isEditor
            || target.isContentEditable
            || $(target).closest('[contenteditable="true"], [contenteditable=""]').length > 0
            || ['input', 'textarea', 'select'].includes(tagName);
    }

    function isPhysicalShortcut(event, code, keyCode) {
        return event.code === code || Number(event.keyCode || event.which || 0) === keyCode;
    }

    function bindShortcuts() {
        $(document).on('keydown.myBoard', function (event) {
            const isAltShortcut = window.AppShortcuts && typeof window.AppShortcuts.isAltPressed === 'function'
                ? window.AppShortcuts.isAltPressed(event) && !event.metaKey && !event.shiftKey
                : event.altKey && !event.metaKey && !event.shiftKey;

            if (shouldIgnoreShortcut(event)) {
                return;
            }

            if (isAltShortcut && isPhysicalShortcut(event, 'Digit1', 49) && $('.modal.show form').length) {
                event.preventDefault();
                $('.modal.show form').trigger('submit');
            } else if (isAltShortcut && isPhysicalShortcut(event, 'Numpad1', 97) && $('.modal.show form').length) {
                event.preventDefault();
                $('.modal.show form').trigger('submit');
            } else if (isAltShortcut && isPhysicalShortcut(event, 'Digit0', 48) && $('.modal.show').length) {
                event.preventDefault();
                $('.modal.show').find('[data-bs-dismiss="modal"]').first().trigger('click');
            } else if (isAltShortcut && isPhysicalShortcut(event, 'Numpad0', 96) && $('.modal.show').length) {
                event.preventDefault();
                $('.modal.show').find('[data-bs-dismiss="modal"]').first().trigger('click');
            } else if (isAltShortcut && isPhysicalShortcut(event, 'Digit9', 57) && state.selectedItem?.can_delete && $(selectors.detailsModal).hasClass('show')) {
                event.preventDefault();
                deleteItem(state.selectedItem.doc_num);
            } else if (isAltShortcut && isPhysicalShortcut(event, 'Numpad9', 105) && state.selectedItem?.can_delete && $(selectors.detailsModal).hasClass('show')) {
                event.preventDefault();
                deleteItem(state.selectedItem.doc_num);
            } else if (isAltShortcut && isPhysicalShortcut(event, 'KeyN', 78)) {
                event.preventDefault();
                openCreateModal('task');
            } else if (isAltShortcut && isPhysicalShortcut(event, 'KeyT', 84)) {
                event.preventDefault();
                openCreateModal('task');
            } else if (isAltShortcut && isPhysicalShortcut(event, 'KeyM', 77)) {
                event.preventDefault();
                openCreateModal('note');
            } else if (isAltShortcut && isPhysicalShortcut(event, 'KeyL', 76) && config.can?.listCreate) {
                event.preventDefault();
                openListModal(activeType());
            } else if (event.key === 'Delete' && state.selectedItem?.can_delete && $(selectors.detailsModal).hasClass('show')) {
                event.preventDefault();
                deleteItem(state.selectedItem.doc_num);
            }
        });
    }

    $(document)
        .on('click', '.js-board-add', function () {
            openCreateModal($(this).data('board-type') || activeType(), $(this).data('board-list'));
        })
        .on('click', '.js-board-refresh', loadBoard)
        .on('click', '.js-board-all-tasks-refresh', loadAllTasks)
        .on('click', '.js-board-all-notes-refresh', loadAllNotes)
        .on('click', '.my-board-card', function (event) {
            if ($(event.target).closest('.dropdown').length) {
                return;
            }

            fetchItem($(this).data('doc-num'), openDetailsModal);
        })
        .on('click', '.js-board-edit', function (event) {
            event.stopPropagation();
            fetchItem($(this).data('doc-num'), openEditModal);
        })
        .on('click', '.js-board-delete-item, .js-board-delete, .js-board-details-delete', function (event) {
            event.stopPropagation();
            deleteItem($(this).data('doc-num'));
        })
        .on('click', '.js-board-details-edit', function () {
            detailsModal.hide();
            fetchItem($(this).data('doc-num'), openEditModal);
        })
        .on('click', '.js-board-open-task', function () {
            fetchItem($(this).data('doc-num'), openDetailsModal);
        })
        .on('click', '.js-board-delete-comment', function () {
            deleteComment($(this).data('comment-id'));
        })
        .on('click', '.js-board-edit-comment-save', submitEditComment)
        .on('click', '.js-board-add-list', function () {
            openListModal(activeType());
        })
        .on('click', '.js-board-edit-list', function () {
            openListModal(null, $(this).data('list'));
        })
        .on('click', '.js-board-delete-list', function () {
            deleteList($(this).data('list'));
        })
        .on('click', '.js-board-toggle-list-tasks', function () {
            const docNum = $(this).data('list');
            setTaskListHidden(docNum, !isTaskListHidden(docNum));
        })
        .on('click', '.js-board-show-list-tasks', function () {
            setTaskListHidden($(this).data('list'), false);
        })
        .on('change', '#board_type', function () {
            const type = $(this).val() || 'task';
            fillListSelect(type, null);
            toggleTaskFields(type);
            setItemActionLabels(type);
        })
        .on('change', '#board_list_doc_num', syncStatusFromList)
        .on('input change', `${selectors.itemForm} input, ${selectors.itemForm} textarea, ${selectors.itemForm} select, ${selectors.listForm} input, ${selectors.listForm} textarea, ${selectors.listForm} select`, function () {
            clearFieldError(this);
        })
        .on('change select2:select select2:clear', '.js-select2-ajax', function () {
            clearFieldError(this);
        })
        .on('change select2:select select2:clear', '.js-board-all-tasks-filter', loadAllTasks)
        .on('change select2:select select2:clear', '.js-board-all-notes-filter', loadAllNotes)
        .on('shown.bs.tab', '#team-all-tasks-tab', function () {
            showTeamContainer('task');
            if (!state.allTasksLoaded) {
                loadAllTasks();
            }
        })
        .on('shown.bs.tab', '#team-all-notes-tab', function () {
            showTeamContainer('note');
            if (!state.allNotesLoaded) {
                loadAllNotes();
            }
        })
        .on('change', '.js-board-user-selector', function () {
            const selected = $(this).val();
            if (!selected) {
                return;
            }

            state.boardUser = { doc_num: selected, label: $(this).find('option:selected').text() };
            $(selectors.itemForm).find('[name="board_user_doc_num"]').val(selected);
            loadBoard();
        });

    $(selectors.itemForm).on('submit', submitItemForm);
    $(selectors.listForm).on('submit', submitListForm);
    $(document).on('submit', selectors.commentForm, submitComment);
    $(selectors.itemModal).on('shown.bs.modal', function () {
        $('#board_title').trigger('focus');
    });
    $(selectors.detailsModal).on('hidden.bs.modal', function () {
        state.selectedItem = null;
    });

    bindShortcuts();

    if (isTeamScreen()) {
        fillAllListFilters();
        loadActiveTeamBoard();
    } else {
        loadBoard();
    }

    loadSummernote().always(initEditor);
})(jQuery, window, document);
