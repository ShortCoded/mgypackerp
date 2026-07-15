(function ($, window, document) {
    'use strict';

    var config = window.taskBoardDisplayConfig || {};
    var labels = config.labels || {};
    var refreshInterval = Math.max(3000, Number.parseInt(config.refreshInterval || config.interval, 10) || 5000);
    var rotationInterval = Math.max(1000, Number.parseInt(config.rotationInterval || config.interval, 10) || 3000);
    var defaultTheme = ['light', 'dark'].indexOf(config.defaultTheme) !== -1 ? config.defaultTheme : 'light';
    var storageKey = String(config.storageKey || 'task-board-display-theme');
    var users = [];
    var activeUserKey = 'all';
    var currentTaskIndex = 0;
    var currentTaskKey = null;
    var loading = false;
    var paused = false;
    var refreshTimer = null;
    var rotationTimer = null;
    var lastChecksum = null;
    var hasStoredTheme = false;

    function label(key) {
        return labels[key] || '';
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function stripHtmlTags(value) {
        if (!value) {
            return '';
        }

        var text = String(value);

        if (text.indexOf('<') === -1) {
            return text;
        }

        var tmp = document.createElement('div');
        tmp.innerHTML = text;

        return (tmp.textContent || tmp.innerText || '').replace(/\n{3,}/g, '\n\n').trim();
    }

    function formatFileSize(bytes) {
        bytes = Number(bytes || 0);

        if (bytes < 1024) {
            return bytes + ' B';
        }

        if (bytes < 1048576) {
            return (bytes / 1024).toFixed(1) + ' KB';
        }

        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function formattedCount(template, count) {
        return String(template || ':count').replace(':count', count);
    }

    function formattedElapsed(template, time) {
        return String(template || ':time').replace(':time', time);
    }

    function normalizedTheme(theme) {
        return ['light', 'dark'].indexOf(theme) !== -1 ? theme : 'light';
    }

    function storedTheme() {
        try {
            var value = window.localStorage ? window.localStorage.getItem(storageKey) : null;

            if (value === 'light' || value === 'dark') {
                hasStoredTheme = true;

                return value;
            }
        } catch (error) {
            hasStoredTheme = false;
        }

        return null;
    }

    function applyTheme(theme, persist) {
        var nextTheme = normalizedTheme(theme);
        var toggleText = nextTheme === 'dark' ? label('lightMode') : label('darkMode');

        document.documentElement.setAttribute('data-theme', nextTheme);
        $('#task-board-theme-toggle').find('span').text(toggleText);

        if (persist) {
            try {
                if (window.localStorage) {
                    window.localStorage.setItem(storageKey, nextTheme);
                    hasStoredTheme = true;
                }
            } catch (error) {
                hasStoredTheme = false;
            }
        }
    }

    function currentTheme() {
        return normalizedTheme(document.documentElement.getAttribute('data-theme') || defaultTheme);
    }

    function badgeClass(priority) {
        if (priority === 'urgent') {
            return 'priority-urgent';
        }

        if (priority === 'high') {
            return 'priority-high';
        }

        if (priority === 'low') {
            return 'priority-low';
        }

        return 'priority-normal';
    }

    function statusActionColorClass(color) {
        if (color === 'primary') return 'action-primary';
        if (color === 'info') return 'action-info';
        if (color === 'success') return 'action-success';
        if (color === 'danger') return 'action-danger';
        return 'action-primary';
    }

    function userByKey(key) {
        var normalizedKey = String(key || '');
        var found = null;

        $.each(users, function (index, user) {
            if (String(user.key || '') === normalizedKey) {
                found = user;

                return false;
            }

            return true;
        });

        return found;
    }

    function activeUser() {
        return userByKey(activeUserKey) || users[0] || null;
    }

    function activeTasks() {
        var user = activeUser();

        return user && $.isArray(user.tasks) ? user.tasks : [];
    }

    function currentTask() {
        var tasks = activeTasks();

        return tasks[currentTaskIndex] || null;
    }

    function taskIndexByKey(tasks, key) {
        var normalizedKey = String(key || '');
        var found = -1;

        $.each(tasks, function (index, task) {
            if (String(task.key || task.doc_num || '') === normalizedKey) {
                found = index;

                return false;
            }

            return true;
        });

        return found;
    }

    function renderTabs() {
        var html = [];

        $.each(users, function (index, user) {
            var isActive = String(user.key || '') === String(activeUserKey || '');
            var count = Number(user.task_count || (user.tasks ? user.tasks.length : 0) || 0);

            html.push(
                '<button class="user-display-chip' + (isActive ? ' is-active' : '') + '" type="button" role="tab" ' +
                'aria-selected="' + (isActive ? 'true' : 'false') + '" data-user-key="' + escapeHtml(user.key) + '">' +
                '<span class="user-display-chip-avatar">' + escapeHtml(user.initials || '') + '</span>' +
                '<span class="user-display-chip-text">' +
                '<strong>' + escapeHtml(user.name || label('allTasks') || '') + '</strong>' +
                '<small>' + escapeHtml(formattedCount(label('taskCount'), count)) + '</small>' +
                '</span>' +
                '</button>'
            );
        });

        $('#task-board-user-tabs').html(html.join('')).toggleClass('is-empty', users.length === 0);

        var $activeTab = $('#task-board-user-tabs .user-display-chip.is-active');

        if ($activeTab.length) {
            var container = document.getElementById('task-board-user-tabs');

            if (container) {
                var tabEl = $activeTab[0];
                var scrollLeft = tabEl.offsetLeft - (container.clientWidth / 2) + (tabEl.clientWidth / 2);
                container.scrollTo({ left: scrollLeft, behavior: 'smooth' });
            }
        }
    }

    function emptyState(primary, secondary) {
        return [
            '<div class="user-display-empty task-display-empty">',
            '<strong>' + escapeHtml(primary) + '</strong>',
            secondary ? '<span>' + escapeHtml(secondary) + '</span>' : '',
            '</div>'
        ].join('');
    }

    function taskCounter(total) {
        if (total <= 1) {
            return '';
        }

        return '<span class="user-display-task-counter">' + escapeHtml((currentTaskIndex + 1) + ' / ' + total) + '</span>';
    }

    function attachmentsButton(task) {
        var attachments = $.isArray(task.attachments) ? task.attachments : [];
        var count = attachments.length;

        if (count <= 0) {
            return '';
        }

        return '<button type="button" class="task-attachments-btn js-display-attachments-btn" data-task-key="' + escapeHtml(task.key || task.doc_num || '') + '">' +
            '<span class="fas fa-paperclip"></span> ' +
            escapeHtml(formattedCount(label('attachmentCount'), count)) +
            '</button>';
    }

    function assigneeChip(task, selectedUser) {
        if (!task.assigned_to || (selectedUser && !selectedUser.is_all)) {
            return '';
        }

        return [
            '<span class="task-display-assignee" dir="auto">',
            task.assigned_to_initials ? '<span class="user-display-assignee-avatar">' + escapeHtml(task.assigned_to_initials) + '</span>' : '',
            '<strong>' + escapeHtml(task.assigned_to) + '</strong>',
            '</span>'
        ].join('');
    }

    function actionButtons(task) {
        if (!task.actions || task.actions.length === 0) {
            return '';
        }

        var buttons = [];

        $.each(task.actions, function (i, action) {
            buttons.push(
                '<button class="task-action-btn ' + statusActionColorClass(action.color) + '" ' +
                'data-doc-num="' + escapeHtml(task.doc_num) + '" ' +
                'data-target-status="' + escapeHtml(action.status) + '" ' +
                'data-action-label="' + escapeHtml(action.label) + '">' +
                escapeHtml(action.label) +
                '</button>'
            );
        });

        return '<div class="task-actions">' + buttons.join('') + '</div>';
    }

    function taskCard(task, total) {
        var selectedUser = activeUser();
        var description = task.summary ? '<p class="task-display-description" dir="auto">' + escapeHtml(stripHtmlTags(task.summary)) + '</p>' : '';
        var elapsed = task.elapsed_time
            ? '<span class="task-meta-time"><span class="task-date" dir="ltr">' + escapeHtml(task.created_at || '') + '</span><span class="task-elapsed" dir="auto">' + escapeHtml(formattedElapsed(label('elapsed'), task.elapsed_time)) + '</span></span>'
            : '<span class="task-meta-time"><span class="task-date" dir="ltr">' + escapeHtml(task.created_at || '') + '</span></span>';
        var metaItems = [
            '<span class="task-number" dir="ltr">' + escapeHtml(task.doc_num) + '</span>',
            '<span class="priority-badge ' + badgeClass(task.priority) + '">' + escapeHtml(task.priority_label) + '</span>',
            '<span class="user-display-status">' + escapeHtml(task.status_label) + '</span>',
            attachmentsButton(task),
            elapsed
        ];

        return [
            '<article class="task-display-card" tabindex="0" data-task-key="' + escapeHtml(task.key || task.doc_num) + '">',
            '<div class="task-display-card-top">',
            assigneeChip(task, selectedUser),
            taskCounter(total),
            '</div>',
            '<div class="task-display-meta-line">',
            metaItems.join(''),
            '</div>',
            '<h2 dir="auto">' + escapeHtml(task.title) + '</h2>',
            description,
            actionButtons(task),
            '</article>'
        ].join('');
    }

    function renderStage() {
        var $stage = $('#task-board-display-stage');
        var tasks = activeTasks();

        if (users.length === 0) {
            $stage.html(emptyState(label('noUsers'), label('noTasks')));
            return;
        }

        if (tasks.length === 0) {
            $stage.html(emptyState(label('noTasksForUser'), ''));
            return;
        }

        if (currentTaskIndex >= tasks.length) {
            currentTaskIndex = 0;
        }

        var task = tasks[currentTaskIndex];
        currentTaskKey = String(task.key || task.doc_num || '');
        var disabled = tasks.length <= 1 ? ' disabled aria-disabled="true"' : '';

        $stage.html([
            '<div class="task-display-shell">',
            '<button type="button" class="task-nav-btn task-nav-prev" data-direction="-1" title="' + escapeHtml(label('previousTask')) + '" aria-label="' + escapeHtml(label('previousTask')) + '"' + disabled + '>',
            '<span class="fas fa-chevron-left"></span><span>' + escapeHtml(label('previousTask')) + '</span>',
            '</button>',
            taskCard(task, tasks.length),
            '<button type="button" class="task-nav-btn task-nav-next" data-direction="1" title="' + escapeHtml(label('nextTask')) + '" aria-label="' + escapeHtml(label('nextTask')) + '"' + disabled + '>',
            '<span>' + escapeHtml(label('nextTask')) + '</span><span class="fas fa-chevron-right"></span>',
            '</button>',
            '</div>'
        ].join(''));
    }

    function render() {
        renderTabs();
        renderStage();
    }

    function stopRotation() {
        if (rotationTimer) {
            window.clearTimeout(rotationTimer);
            rotationTimer = null;
        }
    }

    function scheduleRotation() {
        stopRotation();

        if (paused || activeTasks().length <= 1) {
            return;
        }

        rotationTimer = window.setTimeout(function () {
            rotationTimer = null;

            if (!paused) {
                showTaskAtIndex(currentTaskIndex + 1, { wrap: true, restartTimer: false });
            }

            scheduleRotation();
        }, rotationInterval);
    }

    function restartRotation() {
        scheduleRotation();
    }

    function setPaused(active) {
        paused = !!active;

        if (paused) {
            stopRotation();
            return;
        }

        scheduleRotation();
    }

    function normalizedIndex(index, total, wrap) {
        if (total <= 0) {
            return 0;
        }

        if (wrap) {
            return ((index % total) + total) % total;
        }

        return Math.max(0, Math.min(index, total - 1));
    }

    function showTaskAtIndex(index, options) {
        var opts = options || {};
        var tasks = activeTasks();

        if (tasks.length === 0) {
            currentTaskIndex = 0;
            currentTaskKey = null;
            renderStage();
            stopRotation();
            return;
        }

        currentTaskIndex = normalizedIndex(index, tasks.length, opts.wrap !== false);
        currentTaskKey = String(tasks[currentTaskIndex].key || tasks[currentTaskIndex].doc_num || '');
        renderStage();

        if (opts.restartTimer !== false) {
            restartRotation();
        }
    }

    function showTaskByKey(key, fallbackIndex) {
        var tasks = activeTasks();
        var index = taskIndexByKey(tasks, key);

        showTaskAtIndex(index >= 0 ? index : (fallbackIndex || 0), { wrap: false });
    }

    function selectUser(key) {
        activeUserKey = String(key || 'all');

        if (!activeUser()) {
            activeUserKey = users.length > 0 ? String(users[0].key || 'all') : 'all';
        }

        currentTaskKey = null;
        currentTaskIndex = 0;
        renderTabs();
        showTaskAtIndex(0, { wrap: false });
    }

    function applyResponse(response, force) {
        var previousUserKey = activeUserKey;
        var previousTaskKey = currentTaskKey;
        var previousIndex = currentTaskIndex;

        users = $.isArray(response.users) ? response.users : [];

        if (users.length === 0) {
            activeUserKey = 'all';
            currentTaskIndex = 0;
            currentTaskKey = null;
            render();
            stopRotation();
            return;
        }

        activeUserKey = userByKey(previousUserKey) ? previousUserKey : String(users[0].key || 'all');

        if (!hasStoredTheme && response.board && response.board.display_theme) {
            applyTheme(response.board.display_theme, false);
        }

        if (force || response.checksum !== lastChecksum) {
            renderTabs();
            showTaskByKey(previousTaskKey, previousIndex);
            lastChecksum = response.checksum || null;
            return;
        }

        scheduleRotation();
    }

    function setRefreshing(active) {
        $('#task-board-refresh')
            .prop('disabled', active)
            .find('span')
            .text(active ? label('refreshing') : label('refresh'));
    }

    function showError(message) {
        var $error = $('#task-board-display-error');

        if (!message) {
            $error.prop('hidden', true).text('');
            return;
        }

        $error.prop('hidden', false).text(message);
    }

    function refresh(force) {
        if (!config.dataUrl || loading) {
            return;
        }

        loading = true;
        setRefreshing(true);

        $.ajax({
            url: config.dataUrl,
            type: 'GET',
            headers: { Accept: 'application/json' }
        }).done(function (response) {
            if (!response || response.success === false) {
                showError(response && response.message ? response.message : label('refreshFailed'));
                return;
            }

            showError('');
            $('#task-board-last-updated').text(response.last_updated_at || '-');
            applyResponse(response, force);
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : label('refreshFailed');
            showError(message);
        }).always(function () {
            loading = false;
            setRefreshing(false);
        });
    }

    function initFullscreen() {
        $('#task-board-fullscreen').on('click', function () {
            var element = document.documentElement;

            if (document.fullscreenElement && document.exitFullscreen) {
                document.exitFullscreen();
                return;
            }

            if (element.requestFullscreen) {
                element.requestFullscreen();
            }
        });
    }

    function confirmStatusChange(task, action) {
        if (!window.Swal) {
            return $.Deferred().resolve({ isConfirmed: true }).promise();
        }

        if (action.status === 'done' && label('doneConfirmTitle')) {
            return Swal.fire({
                icon: 'question',
                title: label('doneConfirmTitle'),
                text: label('doneConfirmText'),
                showCancelButton: true,
                confirmButtonText: label('doneConfirmYes') || 'Yes',
                cancelButtonText: label('cancel') || 'No',
                confirmButtonColor: '#00a65a',
                cancelButtonColor: '#748194',
                heightAuto: false
            });
        }

        return $.Deferred().resolve({ isConfirmed: true }).promise();
    }

    function taskForAttachmentButton($button) {
        var key = String($button.data('task-key') || '');
        var tasks = activeTasks();
        var index = taskIndexByKey(tasks, key);

        if (index >= 0) {
            return tasks[index];
        }

        return currentTask();
    }

    function attachmentUrl(attachment, type) {
        if (type === 'download' && attachment.download_url) {
            return attachment.download_url;
        }

        if (type !== 'download' && attachment.preview_url) {
            return attachment.preview_url;
        }

        var base = window.location.pathname.replace(/\/users\/?$/, '').replace(/\/$/, '');
        var suffix = type === 'download' ? '/download' : '';

        return base + '/attachments/' + encodeURIComponent(attachment.public_uuid || '') + suffix;
    }

    function openAttachmentsModal(task) {
        var attachments = task && $.isArray(task.attachments) ? task.attachments : [];
        var html = [
            '<div class="display-modal-overlay">',
            '<div class="display-modal" role="dialog" aria-modal="true">',
            '<div class="display-modal-header">',
            '<h3>' + escapeHtml(formattedCount(label('attachmentCount'), attachments.length)) + '</h3>',
            '<button type="button" class="display-modal-close" aria-label="' + escapeHtml(label('close') || 'Close') + '">&times;</button>',
            '</div>',
            '<div class="display-modal-body">',
            '<div class="display-attachment-list">'
        ];

        $.each(attachments, function (i, att) {
            var isImage = att.mime_type && att.mime_type.indexOf('image/') === 0;
            var isPdf = att.mime_type === 'application/pdf';
            var iconClass = isImage ? 'is-image' : (isPdf ? 'is-pdf' : '');
            var icon = isImage ? '<span class="fas fa-image"></span>' : (isPdf ? '<span class="fas fa-file-pdf"></span>' : '<span class="fas fa-file"></span>');
            var previewUrl = attachmentUrl(att, 'preview');
            var downloadUrl = attachmentUrl(att, 'download');

            html.push(
                '<div class="display-attachment-item">',
                '<div class="display-attachment-icon ' + iconClass + '">' + icon + '</div>',
                '<div class="display-attachment-info">',
                '<span class="display-attachment-name" dir="auto">' + escapeHtml(att.original_name) + '</span>',
                '<span class="display-attachment-size">' + escapeHtml(formatFileSize(att.size)) + '</span>',
                '</div>',
                '<div class="display-attachment-actions">',
                att.is_previewable ? '<a href="' + escapeHtml(previewUrl) + '" target="_blank" rel="noopener" title="' + escapeHtml(label('preview') || 'Preview') + '"><span class="fas fa-eye"></span></a>' : '',
                '<a href="' + escapeHtml(downloadUrl) + '" title="' + escapeHtml(label('download') || 'Download') + '"><span class="fas fa-download"></span></a>',
                '</div>',
                '</div>'
            );

            if (isImage) {
                html.push(
                    '<div class="display-attachment-preview-wrap">',
                    '<img class="display-attachment-preview" src="' + escapeHtml(previewUrl) + '" alt="' + escapeHtml(att.original_name) + '" loading="lazy">',
                    '</div>'
                );
            }
        });

        html.push('</div></div></div></div>');

        setPaused(true);
        $('body').append(html.join(''));
        $('.display-modal-close').trigger('focus');
    }

    function closeAttachmentsModal() {
        $('.display-modal-overlay').remove();
        setPaused(false);
    }

    function initInteractions() {
        $('#task-board-user-tabs').on('click', '.user-display-chip', function () {
            selectUser($(this).data('user-key'));
        });

        $('#task-board-display-stage')
            .on('click', '.task-nav-btn', function () {
                var direction = Number($(this).data('direction') || 0);

                if (!$(this).prop('disabled') && direction !== 0) {
                    showTaskAtIndex(currentTaskIndex + direction, { wrap: true });
                }
            })
            .on('click', '.js-display-attachments-btn', function () {
                openAttachmentsModal(taskForAttachmentButton($(this)));
            })
            .on('click', '.task-action-btn', function () {
                var $btn = $(this);
                var docNum = $btn.data('doc-num');
                var targetStatus = $btn.data('target-status');
                var actionLabel = $btn.data('action-label');

                if ($btn.prop('disabled')) {
                    return;
                }

                confirmStatusChange({ doc_num: docNum }, { status: targetStatus, label: actionLabel }).done(function (result) {
                    if (!result || !result.isConfirmed) {
                        restartRotation();
                        return;
                    }

                    $btn.prop('disabled', true);

                    var postData = {
                        doc_num: docNum,
                        status: targetStatus
                    };

                    if (config.csrfToken) {
                        postData._token = config.csrfToken;
                    }

                    $.ajax({
                        url: config.changeStatusUrl,
                        type: 'POST',
                        data: postData,
                        headers: { Accept: 'application/json' },
                        dataType: 'json'
                    }).done(function (response) {
                        if (response && response.success) {
                            refresh(true);
                        } else {
                            var msg = response && response.message ? response.message : label('statusChangeFailed');
                            showError(msg);
                            $btn.prop('disabled', false);
                            restartRotation();
                        }
                    }).fail(function (xhr) {
                        var msg = label('changeStatusError');

                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            msg = xhr.responseJSON.message;
                        }

                        showError(msg);
                        $btn.prop('disabled', false);
                        restartRotation();
                    });
                });
            })
            .on('mouseenter focusin', '.task-display-card, .task-nav-btn, .task-attachments-btn, .task-action-btn', function () {
                setPaused(true);
            })
            .on('mouseleave focusout', '.task-display-card, .task-nav-btn, .task-attachments-btn, .task-action-btn', function () {
                window.setTimeout(function () {
                    if (!document.activeElement || !$(document.activeElement).closest('.task-display-card, .task-nav-btn, .task-attachments-btn, .task-action-btn').length) {
                        setPaused(false);
                    }
                }, 0);
            });

        $(document)
            .on('click.taskDisplayModal', '.display-modal-overlay', function (e) {
                if (e.target === this) {
                    closeAttachmentsModal();
                }
            })
            .on('click.taskDisplayModal', '.display-modal-close', function () {
                closeAttachmentsModal();
            })
            .on('keydown.taskDisplay', function (e) {
                var isRtl = String(document.documentElement.getAttribute('dir') || '').toLowerCase() === 'rtl';

                if (e.key === 'Escape' && $('.display-modal-overlay').length) {
                    closeAttachmentsModal();
                    return;
                }

                if ($(e.target).is('input, textarea, select, button, a')) {
                    return;
                }

                if (e.key === 'ArrowLeft') {
                    showTaskAtIndex(currentTaskIndex + (isRtl ? 1 : -1), { wrap: true });
                }

                if (e.key === 'ArrowRight') {
                    showTaskAtIndex(currentTaskIndex + (isRtl ? -1 : 1), { wrap: true });
                }
            });
    }

    $(function () {
        $('#task-board-refresh').on('click', function () {
            refresh(true);
        });

        $('#task-board-theme-toggle').on('click', function () {
            applyTheme(currentTheme() === 'dark' ? 'light' : 'dark', true);
        });

        applyTheme(storedTheme() || defaultTheme, false);
        initFullscreen();
        initInteractions();
        refresh(true);

        if (refreshTimer) {
            window.clearInterval(refreshTimer);
        }

        refreshTimer = window.setInterval(function () {
            refresh(false);
        }, refreshInterval);
    });
})(jQuery, window, document);
