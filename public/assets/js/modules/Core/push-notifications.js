(function (window, document) {
  'use strict';

  const config = window.AppPushNotifications || {};
  const toggles = Array.from(document.querySelectorAll('[data-push-notification-toggle]'));
  const statusElements = Array.from(document.querySelectorAll('[data-push-notification-status]'));
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const handledPushIds = new Set();
  const coordinationIdentity = String(config.coordinationIdentity || '');
  const configuredCoordinationTtlMilliseconds = Number(config.coordinationTtlMs);
  const coordinationTtlMilliseconds = Number.isFinite(configuredCoordinationTtlMilliseconds)
    && configuredCoordinationTtlMilliseconds > 0
    ? configuredCoordinationTtlMilliseconds
    : 86400000;
  const coordinationNamespace = `erp-push-notifications:${coordinationIdentity}`;
  const syncStorageKey = `${coordinationNamespace}:subscription-sync`;
  let subscription = null;
  let busy = false;
  let coordinationStorage = null;

  if (!toggles.length) {
    return;
  }

  coordinationStorage = availableStorage();

  function availableStorage() {
    if (!coordinationIdentity) {
      return null;
    }

    try {
      const storage = window.localStorage;
      const probeKey = `${coordinationNamespace}:probe`;

      storage.setItem(probeKey, coordinationIdentity);
      storage.removeItem(probeKey);

      return storage;
    } catch (error) {
      return null;
    }
  }

  function readSyncState() {
    if (!coordinationStorage) {
      return null;
    }

    try {
      const value = coordinationStorage.getItem(syncStorageKey);
      const state = value ? JSON.parse(value) : null;

      if (!state
        || state.identity !== coordinationIdentity
        || typeof state.signature !== 'string'
        || typeof state.publicKey !== 'string'
        || typeof state.contentEncoding !== 'string'
        || !Number.isFinite(Number(state.syncedAt))) {
        return null;
      }

      return state;
    } catch (error) {
      coordinationStorage = null;

      return null;
    }
  }

  function writeSyncState(state) {
    if (!coordinationStorage) {
      return;
    }

    try {
      coordinationStorage.setItem(syncStorageKey, JSON.stringify({
        identity: coordinationIdentity,
        signature: state.signature,
        publicKey: state.publicKey,
        contentEncoding: state.contentEncoding,
        syncedAt: Date.now()
      }));
    } catch (error) {
      coordinationStorage = null;
    }
  }

  function clearSyncState() {
    if (!coordinationStorage) {
      return;
    }

    try {
      coordinationStorage.removeItem(syncStorageKey);
    } catch (error) {
      coordinationStorage = null;
    }
  }

  function fallbackSignature(value) {
    let firstHash = 2166136261;
    let secondHash = 2246822519;

    for (let index = 0; index < value.length; index += 1) {
      const character = value.charCodeAt(index);

      firstHash = Math.imul(firstHash ^ character, 16777619);
      secondHash = Math.imul(secondHash ^ (character + index), 3266489917);
    }

    return `fallback-${(firstHash >>> 0).toString(16).padStart(8, '0')}${(secondHash >>> 0).toString(16).padStart(8, '0')}`;
  }

  async function subscriptionSignature(payload) {
    const keys = payload.keys || {};
    const value = JSON.stringify([
      String(payload.endpoint || ''),
      String(keys.p256dh || ''),
      String(keys.auth || '')
    ]);

    if (window.crypto && window.crypto.subtle && typeof window.TextEncoder === 'function') {
      try {
        const digest = await window.crypto.subtle.digest(
          'SHA-256',
          new window.TextEncoder().encode(value)
        );

        return Array.from(new Uint8Array(digest), function (byte) {
          return byte.toString(16).padStart(2, '0');
        }).join('');
      } catch (error) {
        return fallbackSignature(value);
      }
    }

    return fallbackSignature(value);
  }

  function syncStateIsCurrent(state) {
    const storedState = readSyncState();

    if (!storedState
      || storedState.signature !== state.signature
      || storedState.publicKey !== state.publicKey
      || storedState.contentEncoding !== state.contentEncoding) {
      return false;
    }

    const ageMilliseconds = Date.now() - Number(storedState.syncedAt);

    return ageMilliseconds >= 0 && ageMilliseconds < coordinationTtlMilliseconds;
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

  function permissionStatus() {
    if (!('Notification' in window)) {
      return config.messages?.unavailable || '';
    }

    if (window.Notification.permission === 'denied') {
      return config.messages?.denied || '';
    }

    if (window.Notification.permission === 'granted') {
      return subscription
        ? (config.messages?.subscriptionActive || config.messages?.enabled || '')
        : (config.messages?.subscriptionInactive || '');
    }

    return config.messages?.permissionDefault || '';
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

  async function persistSubscription(currentSubscription, forceSync) {
    const payload = currentSubscription.toJSON();
    const encoding = contentEncoding();
    const state = {
      signature: await subscriptionSignature(payload),
      publicKey: String(config.publicKey || ''),
      contentEncoding: encoding
    };

    if (forceSync !== true && syncStateIsCurrent(state)) {
      return;
    }

    clearSyncState();

    const response = await request(config.storeUrl, 'POST', {
      endpoint: payload.endpoint,
      keys: payload.keys,
      content_encoding: encoding
    });

    if (!response || response.success !== true) {
      throw new Error('Push subscription sync failed');
    }

    writeSyncState(state);
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
      await persistSubscription(currentSubscription, true);
      subscription = currentSubscription;
      setStatus(config.messages?.enabled, false);
    } catch (error) {
      await currentSubscription.unsubscribe().catch(function () {});
      throw error;
    }
  }

  async function disable() {
    const endpoint = subscription?.endpoint;

    clearSyncState();

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
    if (busy) {
      return;
    }

    busy = true;
    updateToggles();

    if (!supported()) {
      setStatus(config.messages?.unavailable, true);
      busy = false;
      updateToggles();
      return;
    }

    try {
      const registration = await window.navigator.serviceWorker.ready;
      subscription = await registration.pushManager.getSubscription();

      if (subscription) {
        await persistSubscription(subscription, false);
      } else {
        clearSyncState();
      }
      setStatus(permissionStatus(), window.Notification.permission === 'denied');
    } catch (error) {
      setStatus(config.messages?.failed, true);
    } finally {
      busy = false;
      updateToggles();
    }
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

      if (window.AppNotificationsClient && typeof window.AppNotificationsClient.refresh === 'function') {
        window.AppNotificationsClient.refresh();
      }
    });
  }

  updateToggles();
  window.addEventListener('load', initialize, { once: true });
})(window, document);
