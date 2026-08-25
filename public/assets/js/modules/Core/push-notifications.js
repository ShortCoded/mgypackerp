(function (window, document) {
  'use strict';

  const config = window.AppPushNotifications || {};
  const toggles = Array.from(document.querySelectorAll('[data-push-notification-toggle]'));
  const statusElements = Array.from(document.querySelectorAll('[data-push-notification-status]'));
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const handledPushIds = new Set();
  let subscription = null;
  let busy = false;

  if (!toggles.length) {
    return;
  }

  function supported() {
    return Boolean(
      config.enabled
      && config.publicKey
      && 'serviceWorker' in window.navigator
      && 'PushManager' in window
      && 'Notification' in window
    );
  }

  function setStatus(message, isError) {
    statusElements.forEach(function (element) {
      element.textContent = message || '';
      element.classList.toggle('text-danger', Boolean(isError));
    });
  }

  function updateToggles() {
    const subscribed = Boolean(subscription);

    toggles.forEach(function (toggle) {
      const icon = toggle.querySelector('[data-push-notification-icon]');
      const label = toggle.querySelector('[data-push-notification-label]');
      const labelText = subscribed ? config.messages?.disable : config.messages?.enable;

      toggle.disabled = busy || !supported();
      toggle.setAttribute('aria-pressed', subscribed ? 'true' : 'false');

      if (label) {
        label.textContent = labelText || '';
      }

      if (icon) {
        icon.setAttribute('class', subscribed ? 'fas fa-bell me-1' : 'fas fa-bell-slash me-1');
      }
    });
  }

  function applicationServerKey(value) {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const bytes = new Uint8Array(raw.length);

    for (let index = 0; index < raw.length; index += 1) {
      bytes[index] = raw.charCodeAt(index);
    }

    return bytes;
  }

  function request(url, method, payload) {
    return window.fetch(url, {
      method: method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken
      },
      body: JSON.stringify(payload)
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('Push subscription request failed');
      }

      return response.json();
    });
  }

  function contentEncoding() {
    if (Array.isArray(window.PushManager.supportedContentEncodings) && window.PushManager.supportedContentEncodings.length) {
      return window.PushManager.supportedContentEncodings[0];
    }

    return 'aes128gcm';
  }

  function persistSubscription(currentSubscription) {
    const payload = currentSubscription.toJSON();

    return request(config.storeUrl, 'POST', {
      endpoint: payload.endpoint,
      keys: payload.keys,
      content_encoding: contentEncoding()
    });
  }

  async function enable() {
    const permission = await window.Notification.requestPermission();

    if (permission !== 'granted') {
      setStatus(config.messages?.denied, true);
      return;
    }

    const registration = await window.navigator.serviceWorker.ready;
    const currentSubscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: applicationServerKey(config.publicKey)
    });

    try {
      await persistSubscription(currentSubscription);
      subscription = currentSubscription;
      setStatus(config.messages?.enabled, false);
    } catch (error) {
      await currentSubscription.unsubscribe().catch(function () {});
      throw error;
    }
  }

  async function disable() {
    const endpoint = subscription?.endpoint;

    if (endpoint) {
      await request(config.destroyUrl, 'DELETE', { endpoint: endpoint });
      await subscription.unsubscribe();
    }

    subscription = null;
    setStatus(config.messages?.disabled, false);
  }

  async function toggle() {
    if (busy || !supported()) {
      return;
    }

    busy = true;
    updateToggles();

    try {
      if (subscription) {
        await disable();
      } else {
        await enable();
      }
    } catch (error) {
      setStatus(config.messages?.failed, true);
    } finally {
      busy = false;
      updateToggles();
    }
  }

  async function initialize() {
    if (!supported()) {
      setStatus(config.messages?.unavailable, true);
      updateToggles();
      return;
    }

    try {
      const registration = await window.navigator.serviceWorker.ready;
      subscription = await registration.pushManager.getSubscription();

      if (subscription) {
        await persistSubscription(subscription);
      }
    } catch (error) {
      setStatus(config.messages?.failed, true);
    }

    updateToggles();
  }

  toggles.forEach(function (toggleButton) {
    toggleButton.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      toggle();
    });
  });

  if ('serviceWorker' in window.navigator) {
    window.navigator.serviceWorker.addEventListener('message', function (event) {
      if (!event.data || event.data.type !== 'ERP_PUSH_NOTIFICATION') {
        return;
      }

      const pushId = String(event.data.notification?.data?.id || event.data.notification?.id || '');

      if (pushId && handledPushIds.has(pushId)) {
        return;
      }

      if (pushId) {
        handledPushIds.add(pushId);
      }

      if (window.AppNotificationSound && typeof window.AppNotificationSound.play === 'function') {
        window.AppNotificationSound.play();
      }

      if (window.AppNotificationsClient && typeof window.AppNotificationsClient.refresh === 'function') {
        window.AppNotificationsClient.refresh({ suppressSound: true });
      }
    });
  }

  updateToggles();
  window.addEventListener('load', initialize, { once: true });
})(window, document);
