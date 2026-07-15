(function (window, document) {
  'use strict';

  const root = document.querySelector('[data-notifications-root]');
  const config = window.AppNotifications || {};

  if (!root || !config.pollUrl) {
    return;
  }

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const countElement = root.querySelector('[data-notifications-count]');
  const listElement = root.querySelector('[data-notifications-list]');
  const readAllButton = root.querySelector('[data-notifications-read-all]');
  let timerId = null;
  let visibleDebounceId = null;
  let inFlight = false;
  let failureCount = 0;
  let baselineReady = false;
  let knownUnreadIds = new Set();

  function escapeHtml(value) {
    const element = document.createElement('div');
    element.textContent = value || '';
    return element.innerHTML;
  }

  function jitter() {
    const min = Number(config.jitterMinMs || 3000);
    const max = Number(config.jitterMaxMs || 10000);
    return Math.floor(Math.random() * (max - min + 1)) + min;
  }

  function nextDelay() {
    const base = document.hidden
      ? Number(config.hiddenIntervalMs || 120000)
      : Number(config.intervalMs || 45000);
    const backoff = failureCount > 0 ? Math.min(failureCount * base, 300000) : base;

    return backoff + jitter();
  }

  function schedule(delay) {
    window.clearTimeout(timerId);
    timerId = window.setTimeout(fetchNotifications, delay === undefined ? nextDelay() : delay);
  }

  function request(url, options) {
    return window.fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken
      }
    }, options || {}));
  }

  function iconFor(category) {
    if (category === 'calendar') {
      return 'fas fa-calendar-alt';
    }

    if (category === 'task') {
      return 'fas fa-tasks';
    }

    return 'fas fa-bell';
  }

  function renderCount(count) {
    if (!countElement) {
      return;
    }

    const numericCount = Number(count || 0);
    countElement.textContent = numericCount > 99 ? '99+' : String(numericCount);
    countElement.classList.toggle('d-none', numericCount < 1);
  }

  function renderNotifications(notifications) {
    if (!listElement) {
      return;
    }

    if (!notifications.length) {
      listElement.innerHTML = `<div class="list-group-item" data-notifications-empty>
        <div class="notification notification-flush">
          <div class="notification-avatar">
            <div class="avatar avatar-2xl me-3">
              <div class="avatar-name rounded-circle bg-200 text-700"><span><span class="fas fa-bell"></span></span></div>
            </div>
          </div>
          <div class="notification-body"><p class="mb-1">${escapeHtml(config.messages?.empty || '')}</p></div>
        </div>
      </div>`;
      return;
    }

    listElement.innerHTML = notifications.map(function (notification) {
      const unreadClass = notification.is_read ? '' : ' bg-light';
      const url = notification.url || '#';

      return `<button class="list-group-item list-group-item-action border-0${unreadClass}" type="button" data-notification-id="${escapeHtml(notification.id)}" data-notification-url="${escapeHtml(url)}">
        <div class="notification notification-flush">
          <div class="notification-avatar">
            <div class="avatar avatar-2xl me-3">
              <div class="avatar-name rounded-circle bg-primary-subtle text-primary"><span><span class="${escapeHtml(iconFor(notification.category))}"></span></span></div>
            </div>
          </div>
          <div class="notification-body text-start">
            <p class="mb-1 fw-semibold">${escapeHtml(notification.title)}</p>
            ${notification.body ? `<p class="mb-1 text-700">${escapeHtml(notification.body)}</p>` : ''}
            <span class="notification-time text-600">${escapeHtml(notification.time)}</span>
          </div>
        </div>
      </button>`;
    }).join('');
  }

  function render(payload) {
    const data = payload && payload.data ? payload.data : {};
    const notifications = Array.isArray(data.notifications) ? data.notifications : [];

    renderCount(data.unread_count || 0);
    notifyForNewUnread(notifications);
    renderNotifications(notifications);
  }

  function unreadIds(notifications) {
    return new Set(notifications.filter(function (notification) {
      return notification && notification.id && !notification.is_read;
    }).map(function (notification) {
      return String(notification.id);
    }));
  }

  function notifyForNewUnread(notifications) {
    const currentUnreadIds = unreadIds(notifications);

    if (!baselineReady) {
      knownUnreadIds = currentUnreadIds;
      baselineReady = true;
      return;
    }

    const hasNewUnread = Array.from(currentUnreadIds).some(function (id) {
      return !knownUnreadIds.has(id);
    });

    knownUnreadIds = currentUnreadIds;

    if (hasNewUnread && window.AppNotificationSound && typeof window.AppNotificationSound.play === 'function') {
      window.AppNotificationSound.play();
    }
  }

  function fetchNotifications() {
    if (inFlight) {
      schedule();
      return;
    }

    inFlight = true;

    request(config.pollUrl)
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Notification poll failed');
        }

        return response.json();
      })
      .then(function (payload) {
        failureCount = 0;
        render(payload);
      })
      .catch(function () {
        failureCount += 1;
      })
      .finally(function () {
        inFlight = false;
        schedule();
      });
  }

  function markAsRead(id, url) {
    const readUrl = String(config.readUrl || '').replace('__NOTIFICATION__', encodeURIComponent(id));

    request(readUrl, { method: 'POST' })
      .then(function () {
        if (url && url !== '#') {
          window.location.href = url;
          return;
        }

        fetchNotifications();
      })
      .catch(function () {
        if (url && url !== '#') {
          window.location.href = url;
        }
      });
  }

  root.addEventListener('click', function (event) {
    const item = event.target.closest('[data-notification-id]');

    if (!item) {
      return;
    }

    event.preventDefault();
    markAsRead(item.getAttribute('data-notification-id'), item.getAttribute('data-notification-url'));
  });

  if (readAllButton) {
    readAllButton.addEventListener('click', function (event) {
      event.preventDefault();

      request(config.readAllUrl, { method: 'POST' })
        .then(function (response) {
          if (response.ok) {
            renderCount(0);
            fetchNotifications();
          }
        });
    });
  }

  document.addEventListener('visibilitychange', function () {
    window.clearTimeout(visibleDebounceId);

    if (!document.hidden) {
      visibleDebounceId = window.setTimeout(fetchNotifications, 1500);
      return;
    }

    schedule();
  });

  fetchNotifications();
})(window, document);
