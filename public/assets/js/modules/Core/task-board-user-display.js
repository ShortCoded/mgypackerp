(function ($, window, document) {
    'use strict';

    var config = window.taskBoardUserDisplayConfig || {};
    var labels = config.labels || {};
    var refreshInterval = Math.max(30000, Number.parseInt(config.refreshInterval, 10) || 45000);
    var rotationInterval = Math.max(1000, Number.parseInt(config.rotationInterval, 10) || 3000);
    var defaultTheme = ['light', 'dark'].indexOf(config.defaultTheme) !== -1 ? config.defaultTheme : 'light';
    var storageKey = String(config.storageKey || 'task-board-user-display-theme');
    var users = [];
    var activeUserKey = null;
    var currentTaskIndex = 0;
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
        $('#task-user-display-theme-toggle').find('span').text(toggleText);

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
        return userByKey(activeUserKey);
    }

    function activeTasks() {
        var user = activeUser();

        return user && $.isArray(user.tasks) ? user.tasks : [];
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
                '<strong>' + escapeHtml(user.name || '') + '</strong>' +
                '<small>' + escapeHtml(formattedCount(label('taskCount'), count)) + '</small>' +
                '</span>' +
                '</button>'
            );
        });

        $('#task-user-tabs').html(html.join('')).toggleClass('is-empty', users.length === 0);

        var $activeTab = $('#task-user-tabs .user-display-chip.is-active');

        if ($activeTab.length) {
            var container = document.getElementById('task-user-tabs');

            if (container) {
                var tabEl = $activeTab[0];
                var scrollLeft = tabEl.offsetLeft - (container.clientWidth / 2) + (tabEl.clientWidth / 2);
                container.scrollTo({ left: scrollLeft, behavior: 'smooth' });
            }
        }
    }

    function emptyState(primary, secondary) {
        return [
            '<div class="user-display-empty">',
            '<strong>' + escapeHtml(primary) + '</strong>',
            secondary ? '<span>' + escapeHtml(secondary) + '</span>' : '',
            '</div>'
        ].join('');
    }

    function taskCard(task, user, total) {
        var description = task.summary ? '<p class="user-display-task-description">' + escapeHtml(stripHtmlTags(task.summary)) + '</p>' : '';
        var attachments = task.attachments || [];
        var attachmentsCount = attachments.length;
        var attachmentsBtn = attachmentsCount > 0
            ? '<button type="button" class="task-attachments-btn js-display-attachments-btn" data-attachments="' + escapeHtml(JSON.stringify(attachments)) + '">' +
              '<span class="fas fa-paperclip"></span> ' +
              escapeHtml(formattedCount(label('attachmentCount'), attachmentsCount)) +
              '</button>'
            : '';
        var elapsed = task.elapsed_time
            ? '<span class="task-meta-time"><span class="task-date" dir="ltr">' + escapeHtml(task.created_at || '') + '</span><span class="task-elapsed">' + escapeHtml(formattedElapsed(label('elapsed'), task.elapsed_time)) + '</span></span>'
            : '<span class="task-meta-time"><span class="task-date" dir="ltr">' + escapeHtml(task.created_at || '') + '</span></span>';
        var counter = total > 1 ? '<span class="user-display-task-counter">' + escapeHtml((currentTaskIndex + 1) + ' / ' + total) + '</span>' : '';

        return [
            '<article class="user-display-task-card" tabindex="0" data-task-key="' + escapeHtml(task.key || task.doc_num) + '">',
            '<div class="user-display-task-top">',
            '<div class="user-display-assignee">',
            '<span class="user-display-assignee-avatar">' + escapeHtml(user.initials || '') + '</span>',
            '<div><strong>' + escapeHtml(user.name || '') + '</strong></div>',
            '</div>',
            counter,
            '</div>',
            '<div class="user-display-task-meta-line">',
            '<span class="task-number" dir="ltr">' + escapeHtml(task.doc_num) + '</span>',
            '<span class="priority-badge ' + badgeClass(task.priority) + '">' + escapeHtml(task.priority_label) + '</span>',
            '<span class="user-display-status">' + escapeHtml(task.status_label) + '</span>',
            '</div>',
            '<h2>' + escapeHtml(task.title) + '</h2>',
            description,
            '<div class="task-meta">',
            elapsed,
            attachmentsBtn,
            '</div>',
            '</article>'
        ].join('');
    }

    function renderStage() {
        var $stage = $('#task-user-display-stage');

        if (users.length === 0) {
            $stage.html(emptyState(label('noUsers'), label('noTasks')));
            return;
        }

        var user = activeUser();

        if (!user) {
            $stage.html(emptyState(label('noTasksForUser'), ''));
            return;
        }

        var tasks = activeTasks();

        if (tasks.length === 0) {
            $stage.html(emptyState(label('noTasksForUser'), ''));
            return;
        }

        if (currentTaskIndex >= tasks.length) {
            currentTaskIndex = 0;
        }

        $stage.html(taskCard(tasks[currentTaskIndex], user, tasks.length));
    }

    function render() {
        renderTabs();
        renderStage();
    }

    function stopRotation() {
        if (rotationTimer) {
            window.clearInterval(rotationTimer);
            rotationTimer = null;
        }
    }

    function startRotation() {
        stopRotation();

        if (activeTasks().length <= 1) {
            return;
        }

        rotationTimer = window.setInterval(function () {
            var tasks = activeTasks();

            if (paused || tasks.length <= 1) {
                return;
            }

            currentTaskIndex = (currentTaskIndex + 1) % tasks.length;
            renderStage();
        }, rotationInterval);
    }

    function setPaused(active) {
        paused = !!active;
    }

    function selectUser(key) {
        activeUserKey = String(key || '');
        currentTaskIndex = 0;
        setPaused(false);
        render();
        startRotation();
    }

    function setRefreshing(active) {
        $('#task-user-display-refresh')
            .prop('disabled', active)
            .find('span')
            .text(active ? label('refreshing') : label('refresh'));
    }

    function showError(message) {
        var $error = $('#task-user-display-error');

        if (!message) {
            $error.prop('hidden', true).text('');
            return;
        }

        $error.prop('hidden', false).text(message);
    }

    function applyResponse(response, force) {
        users = $.isArray(response.users) ? response.users : [];

        if (!activeUserKey && users.length > 0) {
            activeUserKey = String(users[0].key || '');
            currentTaskIndex = 0;
        }

        if (activeUser()) {
            currentTaskIndex = Math.min(currentTaskIndex, Math.max(activeTasks().length - 1, 0));
        }

        if (!hasStoredTheme && response.board && response.board.display_theme) {
            applyTheme(response.board.display_theme, false);
        }

        if (force || response.checksum !== lastChecksum) {
            render();
            startRotation();
            lastChecksum = response.checksum || null;
        }
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
            $('#task-user-display-last-updated').text(response.last_updated_at || '-');
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
        $('#task-user-display-fullscreen').on('click', function () {
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

    function initInteractions() {
        $('#task-user-tabs').on('click', '.user-display-chip', function () {
            selectUser($(this).data('user-key'));
        });

        $('#task-user-display-stage')
            .on('mouseenter', '.user-display-task-card', function () {
                setPaused(true);
            })
            .on('mouseleave', '.user-display-task-card', function () {
                setPaused(false);
            })
            .on('focusin', '.user-display-task-card', function () {
                setPaused(true);
            })
            .on('focusout', '.user-display-task-card', function () {
                window.setTimeout(function () {
                    if (!document.activeElement || !$(document.activeElement).closest('.user-display-task-card').length) {
                        setPaused(false);
                    }
                }, 0);
            });

        $(document).on('click', '.js-display-attachments-btn', function () {
            var attachments = [];

            try {
                attachments = JSON.parse($(this).data('attachments') || '[]');
            } catch (e) {
                attachments = [];
            }

            openAttachmentsModal(attachments);
        });

        $(document).on('click', '.display-modal-overlay', function (e) {
            if (e.target === this) {
                closeAttachmentsModal();
            }
        });

        $(document).on('click', '.display-modal-close', function () {
            closeAttachmentsModal();
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && !$('.display-modal-overlay[hidden]').length) {
                closeAttachmentsModal();
            }
        });
    }

    function openAttachmentsModal(attachments) {
        setPaused(true);

        var html = [
            '<div class="display-modal-overlay">',
            '<div class="display-modal">',
            '<div class="display-modal-header">',
            '<h3>' + escapeHtml(label('attachmentCount')) + ' (' + attachments.length + ')</h3>',
            '<button type="button" class="display-modal-close" aria-label="Close">&times;</button>',
            '</div>',
            '<div class="display-modal-body">',
            '<div class="display-attachment-list">'
        ];

        $.each(attachments, function (i, att) {
            var isImage = att.mime_type && att.mime_type.indexOf('image/') === 0;
            var iconClass = isImage ? 'is-image' : '';
            var icon = isImage ? '<span class="fas fa-image"></span>' : (att.mime_type === 'application/pdf' ? '<span class="fas fa-file-pdf"></span>' : '<span class="fas fa-file"></span>');
            var previewUrl = routeAttachment('show', att);
            var downloadUrl = routeAttachment('download', att);

            html.push(
                '<div class="display-attachment-item">',
                '<div class="display-attachment-icon ' + iconClass + '">' + icon + '</div>',
                '<div class="display-attachment-info">',
                '<span class="display-attachment-name">' + escapeHtml(att.original_name) + '</span>',
                '<span class="display-attachment-size">' + escapeHtml(formatFileSize(att.size)) + '</span>',
                '</div>',
                '<div class="display-attachment-actions">',
                att.is_previewable ? '<a href="' + escapeHtml(previewUrl) + '" target="_blank" rel="noopener" title="Preview"><span class="fas fa-eye"></span></a>' : '',
                '<a href="' + escapeHtml(downloadUrl) + '" title="Download"><span class="fas fa-download"></span></a>',
                '</div>',
                '</div>'
            );

            if (isImage) {
                html.push(
                    '<div class="display-attachment-preview-wrap" style="margin-top:.5rem">',
                    '<img class="display-attachment-preview" src="' + escapeHtml(previewUrl) + '" alt="' + escapeHtml(att.original_name) + '" loading="lazy">',
                    '</div>'
                );
            }
        });

        html.push('</div></div></div></div>');

        $('body').append(html.join(''));
    }

    function closeAttachmentsModal() {
        $('.display-modal-overlay').remove();
        setPaused(false);
    }

    function routeAttachment(action, attachment) {
        if (action === 'download' && attachment.download_url) {
            return attachment.download_url;
        }

        if (action !== 'download' && attachment.preview_url) {
            return attachment.preview_url;
        }

        var base = window.location.pathname.replace(/\/users\/?$/, '').replace(/\/$/, '');
        var suffix = action === 'download' ? '/download' : '';

        return base + '/attachments/' + encodeURIComponent(attachment.public_uuid) + suffix;
    }

    $(function () {
        $('#task-user-display-refresh').on('click', function () {
            refresh(true);
        });

        $('#task-user-display-theme-toggle').on('click', function () {
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
