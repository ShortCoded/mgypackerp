(function (window, document) {
  'use strict';

  const root = document.querySelector('[data-notifications-root]');
  const config = window.AppNotifications || {};
  const center = document.querySelector('[data-notifications-center]');

  if (!root || !config.pollUrl) {
    return;
  }

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const countElement = root.querySelector('[data-notifications-count]');
  const listElement = root.querySelector('[data-notifications-list]');
  const readAllButtons = Array.from(document.querySelectorAll('[data-notifications-read-all]'));
  const healthElements = Array.from(document.querySelectorAll('[data-notifications-health]'));
  const coordinationIdentity = String(config.coordinationIdentity || '');
  const tabId = createTabId();
  const coordinationNamespace = `erp-notifications:${coordinationIdentity}:${String(config.pollUrl)}`;
  const leaderStorageKey = `${coordinationNamespace}:leader`;
  const messageStorageKey = `${coordinationNamespace}:message`;
  const leaderLeaseMs = 10000;
  const leaderHeartbeatMs = 3000;
  const electionMinMs = 80;
  const electionMaxMs = 240;
  let timerId = null;
  let visibleDebounceId = null;
  let electionTimerId = null;
  let heartbeatTimerId = null;
  let coordinationStorage = null;
  let coordinationChannel = null;
  let isLeader = false;
  let leadershipVersion = 0;
  let inFlight = false;
  let failureCount = 0;
  let baselineReady = false;
  let highWaterSequence = 0;
  let lastPayload = null;
  let messageSequence = 0;

  function createTabId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }

    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  }

  function electionDelay() {
    return Math.floor(Math.random() * (electionMaxMs - electionMinMs + 1)) + electionMinMs;
  }

  function availableStorage() {
    if (!coordinationIdentity) {
      return null;
    }

    try {
      const storage = window.localStorage;
      const probeKey = `${coordinationNamespace}:probe:${tabId}`;

      storage.setItem(probeKey, tabId);
      storage.removeItem(probeKey);

      return storage;
    } catch (error) {
      return null;
    }
  }

  function parseLeader(value) {
    if (!value) {
      return null;
    }

    try {
      const leader = JSON.parse(value);

      if (!leader
        || leader.identity !== coordinationIdentity
        || typeof leader.id !== 'string'
        || !Number.isFinite(Number(leader.expiresAt))) {
        return null;
      }

      return { id: leader.id, expiresAt: Number(leader.expiresAt) };
    } catch (error) {
      return null;
    }
  }

  function readLeader() {
    if (!coordinationStorage) {
      return null;
    }

    try {
      return parseLeader(coordinationStorage.getItem(leaderStorageKey));
    } catch (error) {
      coordinationStorage = null;

      return null;
    }
  }

  function leaderIsActive(leader) {
    return Boolean(leader && leader.expiresAt > Date.now());
  }

  function writeLeader() {
    if (!coordinationStorage) {
      return false;
    }

    try {
      coordinationStorage.setItem(leaderStorageKey, JSON.stringify({
        id: tabId,
        identity: coordinationIdentity,
        expiresAt: Date.now() + leaderLeaseMs
      }));

      return true;
    } catch (error) {
      coordinationStorage = null;

      return false;
    }
  }

  function ownsLeadership() {
    const leader = readLeader();

    return leaderIsActive(leader) && leader.id === tabId;
  }

  function updateLeadership(value) {
    if (isLeader === value) {
      return;
    }

    isLeader = value;
    leadershipVersion += 1;
  }

  function clearLeaderTimers() {
    window.clearTimeout(timerId);
    window.clearTimeout(electionTimerId);
    window.clearTimeout(heartbeatTimerId);
    timerId = null;
    electionTimerId = null;
    heartbeatTimerId = null;
  }

  function postCoordinationMessage(message) {
    if (!coordinationStorage) {
      return;
    }

    messageSequence += 1;

    const envelope = Object.assign({}, message, {
      identity: coordinationIdentity,
      source: tabId,
      nonce: `${tabId}:${messageSequence}`
    });

    if (coordinationChannel) {
      try {
        coordinationChannel.postMessage(envelope);
        return;
      } catch (error) {
        try {
          coordinationChannel.close();
        } catch (closeError) {
          // Use the storage-event transport below.
        }

        coordinationChannel = null;
      }
    }

    try {
      coordinationStorage.setItem(messageStorageKey, JSON.stringify(envelope));
      coordinationStorage.removeItem(messageStorageKey);
    } catch (error) {
      // The leader lease remains valid even when a payload is too large to relay.
    }
  }

  function openCoordinationChannel() {
    if (typeof window.BroadcastChannel !== 'function') {
      return;
    }

    try {
      coordinationChannel = new window.BroadcastChannel(`${coordinationNamespace}:channel`);
      coordinationChannel.onmessage = function (event) {
        handleCoordinationMessage(event.data);
      };
    } catch (error) {
      coordinationChannel = null;
    }
  }

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
    timerId = null;

    if (!isLeader) {
      return;
    }

    timerId = window.setTimeout(function () {
      timerId = null;
      fetchNotifications();
    }, delay === undefined ? nextDelay() : delay);
  }

  function scheduleLeadershipAttempt(leader, delay) {
    window.clearTimeout(electionTimerId);
    electionTimerId = null;

    if (!coordinationStorage || document.hidden || isLeader) {
      return;
    }

    const wait = delay === undefined
      ? (leaderIsActive(leader) ? Math.max(electionMinMs, leader.expiresAt - Date.now() + electionMinMs) : electionDelay())
      : Math.max(0, delay);

    electionTimerId = window.setTimeout(function () {
      electionTimerId = null;
      attemptLeadership();
    }, wait);
  }

  function becomeFollower(leader) {
    updateLeadership(false);
    clearLeaderTimers();
    scheduleLeadershipAttempt(leader);
  }

  function renewLeadership() {
    if (!coordinationStorage || !isLeader || document.hidden) {
      return false;
    }

    const leader = readLeader();

    if (leaderIsActive(leader) && leader.id !== tabId) {
      return false;
    }

    return writeLeader() && ownsLeadership();
  }

  function scheduleHeartbeat() {
    window.clearTimeout(heartbeatTimerId);

    if (!isLeader || !coordinationStorage) {
      heartbeatTimerId = null;
      return;
    }

    heartbeatTimerId = window.setTimeout(function () {
      heartbeatTimerId = null;

      if (!renewLeadership()) {
        if (!coordinationStorage) {
          startIndependentPolling();
          return;
        }

        becomeFollower(readLeader());
        return;
      }

      scheduleHeartbeat();
    }, leaderHeartbeatMs);
  }

  function activateLeadership() {
    electionTimerId = null;

    if (document.hidden || !ownsLeadership()) {
      if (!coordinationStorage) {
        startIndependentPolling();
        return;
      }

      becomeFollower(readLeader());
      return;
    }

    updateLeadership(true);
    scheduleHeartbeat();
    fetchNotifications();
  }

  function attemptLeadership() {
    if (!coordinationStorage || document.hidden || isLeader) {
      return;
    }

    const leader = readLeader();

    if (leaderIsActive(leader) && leader.id !== tabId) {
      scheduleLeadershipAttempt(leader);
      postCoordinationMessage({ type: 'sync-request' });
      return;
    }

    if (!writeLeader()) {
      startIndependentPolling();
      return;
    }

    window.clearTimeout(electionTimerId);
    electionTimerId = window.setTimeout(activateLeadership, electionDelay());
  }

  function releaseLeadership() {
    if (!coordinationStorage) {
      return;
    }

    const leader = readLeader();

    if (!coordinationStorage) {
      updateLeadership(true);
      schedule();
      return;
    }

    const ownsStoredLease = leader?.id === tabId;

    updateLeadership(false);
    clearLeaderTimers();

    if (!ownsStoredLease) {
      return;
    }

    try {
      coordinationStorage.removeItem(leaderStorageKey);
    } catch (error) {
      coordinationStorage = null;
      updateLeadership(true);
      schedule();
      return;
    }

    postCoordinationMessage({ type: 'leader-released' });
  }

  function startIndependentPolling() {
    clearLeaderTimers();
    coordinationStorage = null;

    if (coordinationChannel) {
      try {
        coordinationChannel.close();
      } catch (error) {
        // The channel is no longer needed by this tab.
      }

      coordinationChannel = null;
    }

    updateLeadership(true);
    fetchNotifications();
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

    if (category === 'chat') {
      return 'fas fa-comment-alt';
    }

    if (category === 'maintenance') {
      return 'fas fa-tools';
    }

    if (category === 'quality') {
      return 'fas fa-clipboard-check';
    }

    return 'fas fa-bell';
  }

  function renderCount(count) {
    if (!countElement) {
      return;
    }

    const numericCount = Number(count || 0);
    const indicatorElement = typeof countElement.closest === 'function'
      ? countElement.closest('.notification-indicator')
      : null;

    countElement.textContent = numericCount > 99 ? '99+' : String(numericCount);
    countElement.classList.toggle('d-none', numericCount < 1);
    indicatorElement?.classList.toggle('has-unread', numericCount > 0);
    indicatorElement?.classList.toggle('notification-indicator-primary', numericCount > 0);
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
      const unreadClass = notification.is_read ? '' : ' notification-unread';
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

  function render(payload, options) {
    const data = payload && payload.data ? payload.data : {};
    const notifications = Array.isArray(data.notifications) ? data.notifications : [];

    renderCount(data.unread_count || 0);
    notifyForNewUnread(notifications, Boolean(options?.suppressSound));
    renderNotifications(notifications);
  }

  function payloadMatchesCurrentSession(payload) {
    return coordinationIdentity !== ''
      && payload
      && payload.data
      && payload.data.session_identity === coordinationIdentity;
  }

  function rejectStaleSessionPayload() {
    updateLeadership(false);
    clearLeaderTimers();

    if (window.location) {
      window.location.href = window.location.href;
    }
  }

  function notificationSequence(notification) {
    const sequence = Number(notification?.sequence || 0);

    return Number.isFinite(sequence) ? sequence : 0;
  }

  function notifyForNewUnread(notifications, suppressSound) {
    const highestSequence = notifications.reduce(function (highest, notification) {
      return Math.max(highest, notificationSequence(notification));
    }, highWaterSequence);

    if (!baselineReady) {
      highWaterSequence = highestSequence;
      baselineReady = true;
      return;
    }

    const newUnread = notifications.filter(function (notification) {
      return notification && !notification.is_read && notificationSequence(notification) > highWaterSequence;
    });

    highWaterSequence = highestSequence;

    if (!newUnread.length || suppressSound) {
      return;
    }

    const visibleNotifications = newUnread.filter(function (notification) {
      const viewing = window.AppChatNotificationContext
        && typeof window.AppChatNotificationContext.isViewing === 'function'
        && window.AppChatNotificationContext.isViewing(notification);

      return !viewing && notification?.suppress_in_app_alert !== true;
    });

    if (!visibleNotifications.length) {
      return;
    }

    const soundNotification = visibleNotifications.find(function (notification) {
      return notification.sound_key === 'urgent';
    }) || visibleNotifications.find(function (notification) {
      return notification.sound_key === 'action';
    }) || visibleNotifications.find(function (notification) {
      return notification.sound_key === 'chat';
    });

    if (window.AppNotificationSound && typeof window.AppNotificationSound.play === 'function') {
      window.AppNotificationSound.play(soundNotification?.sound_key || 'action');
    }

    if (!window.AppAlerts || typeof window.AppAlerts.toast !== 'function') {
      return;
    }

    if (visibleNotifications.length > 3) {
      const title = String(config.messages?.batchReceived || '')
        .replace(':count', String(visibleNotifications.length));
      window.AppAlerts.toast('info', title);
      return;
    }

    visibleNotifications.forEach(showNotificationToast);
  }

  function showNotificationToast(notification) {
    const icon = notification.severity === 'urgent'
      ? 'warning'
      : (notification.requires_action ? 'info' : 'success');

    window.AppAlerts.toast(icon, notification.title, {
      text: notification.body || '',
      timer: notification.severity === 'urgent' ? 9000 : 6000,
      didOpen: function (element) {
        element.setAttribute('role', 'button');
        element.setAttribute('tabindex', '0');
        element.style.cursor = 'pointer';

        function openNotification() {
          markAsRead(notification.id, notification.url);
        }

        element.addEventListener('click', openNotification);
        element.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openNotification();
          }
        });

        if (window.Swal) {
          element.addEventListener('mouseenter', window.Swal.stopTimer);
          element.addEventListener('mouseleave', window.Swal.resumeTimer);
        }
      }
    });
  }

  function renderHealth(message, failed) {
    healthElements.forEach(function (element) {
      element.textContent = message || '';
      element.classList.toggle('text-danger', Boolean(failed));
    });
  }

  function handleCoordinationMessage(message) {
    if (!message || message.identity !== coordinationIdentity || message.source === tabId) {
      return;
    }

    if (message.type === 'payload' && message.payload) {
      if (!payloadMatchesCurrentSession(message.payload)) {
        rejectStaleSessionPayload();
        return;
      }

      lastPayload = message.payload;
      render(message.payload, { suppressSound: true });
      window.dispatchEvent(new CustomEvent('erp:notifications-updated', { detail: message.payload.data || {} }));
      return;
    }

    if (message.type === 'refresh' && isLeader) {
      fetchNotifications({ suppressSound: Boolean(message.suppressSound) });
      return;
    }

    if (message.type === 'sync-request' && isLeader) {
      if (lastPayload) {
        postCoordinationMessage({ type: 'payload', payload: lastPayload });
      } else {
        fetchNotifications({ suppressSound: true });
      }
      return;
    }

    if (message.type === 'leader-released') {
      scheduleLeadershipAttempt(readLeader(), electionDelay());
    }
  }

  function handleStorageEvent(event) {
    if (!coordinationStorage) {
      return;
    }

    if (event.key === messageStorageKey && event.newValue) {
      try {
        handleCoordinationMessage(JSON.parse(event.newValue));
      } catch (error) {
        return;
      }

      return;
    }

    if (event.key !== leaderStorageKey) {
      return;
    }

    const leader = readLeader();

    if (!coordinationStorage) {
      startIndependentPolling();
      return;
    }

    if (leaderIsActive(leader) && leader.id !== tabId) {
      const needsPayload = !lastPayload;

      becomeFollower(leader);

      if (needsPayload) {
        postCoordinationMessage({ type: 'sync-request' });
      }

      return;
    }

    if (!leaderIsActive(leader) && !document.hidden) {
      becomeFollower(null);
    }
  }

  function pollIsCurrent(version) {
    return isLeader
      && version === leadershipVersion
      && (!coordinationStorage || ownsLeadership());
  }

  function fetchNotifications(options) {
    window.clearTimeout(timerId);
    timerId = null;

    if (!isLeader) {
      const leader = readLeader();

      if (!coordinationStorage) {
        startIndependentPolling();
        return;
      }

      postCoordinationMessage({
        type: 'refresh',
        suppressSound: Boolean(options?.suppressSound)
      });

      if (!leaderIsActive(leader)) {
        scheduleLeadershipAttempt(null);
      }

      return;
    }

    if (coordinationStorage && !renewLeadership()) {
      if (!coordinationStorage) {
        startIndependentPolling();
        return;
      }

      becomeFollower(readLeader());
      postCoordinationMessage({
        type: 'refresh',
        suppressSound: Boolean(options?.suppressSound)
      });
      return;
    }

    if (inFlight) {
      schedule(electionMinMs);
      return;
    }

    inFlight = true;
    const pollVersion = leadershipVersion;

    request(config.pollUrl)
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Notification poll failed');
        }

        return response.json();
      })
      .then(function (payload) {
        if (!pollIsCurrent(pollVersion)) {
          return;
        }

        if (!payloadMatchesCurrentSession(payload)) {
          rejectStaleSessionPayload();
          return;
        }

        failureCount = 0;
        renderHealth(config.messages?.updatedNow || '', false);
        lastPayload = payload;
        render(payload, options);
        postCoordinationMessage({ type: 'payload', payload: payload });
        window.dispatchEvent(new CustomEvent('erp:notifications-updated', { detail: payload.data || {} }));
      })
      .catch(function () {
        if (pollIsCurrent(pollVersion)) {
          failureCount += 1;
          renderHealth(config.messages?.updateFailed || '', true);
        }
      })
      .finally(function () {
        inFlight = false;

        if (pollIsCurrent(pollVersion)) {
          schedule();
        } else if (isLeader) {
          schedule(electionMinMs);
        }
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

  readAllButtons.forEach(function (readAllButton) {
    readAllButton.addEventListener('click', function (event) {
      event.preventDefault();

      if (readAllButton.disabled) {
        return;
      }

      readAllButton.disabled = true;

      request(config.readAllUrl, { method: 'POST' })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Could not mark notifications as read');
          }

          return response.json();
        })
        .then(function (payload) {
          renderCount(payload?.data?.unread_count || 0);

          if (center) {
            window.location.href = window.location.href;
            return;
          }

          fetchNotifications({ suppressSound: true });
        })
        .catch(function () {
          readAllButton.disabled = false;

          if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast('error', config.messages?.actionFailed || config.messages?.updateFailed || '');
          }
        });
    });
  });

  document.addEventListener('visibilitychange', function () {
    window.clearTimeout(visibleDebounceId);

    if (!document.hidden) {
      visibleDebounceId = window.setTimeout(function () {
        if (!coordinationStorage) {
          fetchNotifications();
          return;
        }

        attemptLeadership();
        postCoordinationMessage({ type: 'sync-request' });
      }, 1500);
      return;
    }

    if (coordinationStorage) {
      releaseLeadership();
      return;
    }

    schedule();
  });

  window.addEventListener('storage', handleStorageEvent);
  window.addEventListener('pagehide', releaseLeadership);
  window.addEventListener('beforeunload', releaseLeadership);
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted || !coordinationStorage || document.hidden) {
      return;
    }

    attemptLeadership();
    postCoordinationMessage({ type: 'sync-request' });
  });

  window.AppNotificationsClient = {
    refresh: fetchNotifications
  };

  coordinationStorage = availableStorage();

  if (!coordinationStorage) {
    startIndependentPolling();
    return;
  }

  openCoordinationChannel();
  attemptLeadership();
})(window, document);
