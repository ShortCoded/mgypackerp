(function (window, document) {
  'use strict';

  const config = window.AppNotificationSoundConfig || {};
  const storageKey = config.storageKey || 'erp_notification_sound_enabled';
  const throttleMs = Number(config.throttleMs || 3000);
  const volume = Number(config.volume || 0.35);
  const source = config.source || '';
  let audio = null;
  let unlocked = false;
  let lastPlayedAt = 0;

  function storedValue() {
    try {
      return window.localStorage.getItem(storageKey);
    } catch (error) {
      return null;
    }
  }

  function setStoredValue(enabled) {
    try {
      window.localStorage.setItem(storageKey, enabled ? '1' : '0');
    } catch (error) {
      // Local storage can be unavailable in private contexts. Sound simply keeps the in-memory default.
    }
  }

  function isEnabled() {
    return storedValue() !== '0';
  }

  function sound() {
    if (!source || !window.Audio) {
      return null;
    }

    if (!audio) {
      audio = new Audio(source);
      audio.preload = 'auto';
      audio.volume = volume;
    }

    return audio;
  }

  function updateToggle(toggle) {
    if (!toggle) {
      return;
    }

    const enabled = isEnabled();
    const onLabel = toggle.getAttribute('data-label-on') || 'Sound on';
    const offLabel = toggle.getAttribute('data-label-off') || 'Sound off';
    const icon = toggle.querySelector('[data-notification-sound-icon]');
    const label = toggle.querySelector('[data-notification-sound-label]');

    toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
    toggle.setAttribute('title', enabled ? onLabel : offLabel);

    if (label) {
      label.textContent = enabled ? onLabel : offLabel;
    }

    if (icon) {
      icon.className = enabled ? 'fas fa-volume-up me-1' : 'fas fa-volume-mute me-1';
    }
  }

  function updateToggles() {
    document.querySelectorAll('[data-notification-sound-toggle]').forEach(updateToggle);
  }

  function unlock() {
    const item = sound();

    if (!item || unlocked) {
      return;
    }

    const originalVolume = item.volume;
    item.volume = 0;

    const promise = item.play();

    if (promise && typeof promise.then === 'function') {
      promise
        .then(function () {
          item.pause();
          item.currentTime = 0;
          item.volume = originalVolume;
          unlocked = true;
        })
        .catch(function () {
          item.volume = originalVolume;
        });
      return;
    }

    item.pause();
    item.currentTime = 0;
    item.volume = originalVolume;
    unlocked = true;
  }

  function play() {
    if (!isEnabled() || !unlocked) {
      return;
    }

    const now = Date.now();

    if (now - lastPlayedAt < throttleMs) {
      return;
    }

    const item = sound();

    if (!item) {
      return;
    }

    lastPlayedAt = now;
    item.volume = volume;
    item.currentTime = 0;

    const promise = item.play();

    if (promise && typeof promise.catch === 'function') {
      promise.catch(function () {});
    }
  }

  function setEnabled(enabled) {
    setStoredValue(enabled);
    updateToggles();

    if (enabled) {
      unlock();
    }
  }

  function bindUnlock() {
    ['click', 'keydown', 'touchstart'].forEach(function (eventName) {
      document.addEventListener(eventName, unlock, { once: true, passive: true });
    });
  }

  document.addEventListener('click', function (event) {
    const toggle = event.target.closest('[data-notification-sound-toggle]');

    if (!toggle) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    setEnabled(!isEnabled());
  });

  window.AppNotificationSound = {
    isEnabled: isEnabled,
    play: play,
    setEnabled: setEnabled,
    updateToggles: updateToggles
  };

  bindUnlock();

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', updateToggles);
  } else {
    updateToggles();
  }
})(window, document);
