(function (window, document) {
  'use strict';

  const config = window.AppNotificationSoundConfig || {};
  const sources = Object.assign({}, config.sources || {});
  const enabledStorageKey = config.enabledStorageKey || 'erp_notification_sound_enabled';
  const volumeStorageKey = config.volumeStorageKey || 'erp_notification_sound_volume';
  const lastTestStorageKey = config.lastTestStorageKey || 'erp_notification_sound_last_test';
  const throttleMs = Number(config.throttleMs || 2500);
  const defaultVolume = clamp(Number(config.defaultVolume || 0.55));
  const audioByKey = new Map();
  let activeAudio = null;
  let unlocked = false;
  let lastPlayedAt = 0;

  function clamp(value) {
    if (!Number.isFinite(value)) {
      return 0.55;
    }

    return Math.max(0, Math.min(1, value));
  }

  function storedValue(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (error) {
      return null;
    }
  }

  function storeValue(key, value) {
    try {
      window.localStorage.setItem(key, String(value));
    } catch (error) {
      // Private browser contexts may keep this preference in memory only.
    }
  }

  function isEnabled() {
    return storedValue(enabledStorageKey) === '1';
  }

  function volume() {
    const stored = Number(storedValue(volumeStorageKey));

    return Number.isFinite(stored) ? clamp(stored) : defaultVolume;
  }

  function audioFor(key) {
    const soundKey = Object.prototype.hasOwnProperty.call(sources, key) ? key : 'action';
    const source = sources[soundKey] || '';

    if (!source || !window.Audio) {
      return null;
    }

    if (!audioByKey.has(soundKey)) {
      const audio = new Audio(source);
      audio.preload = 'auto';
      audioByKey.set(soundKey, audio);
    }

    return audioByKey.get(soundKey);
  }

  function setStatus(message, isError) {
    document.querySelectorAll('[data-notification-sound-status]').forEach(function (element) {
      element.textContent = message || '';
      element.classList.toggle('text-danger', Boolean(isError));
    });
  }

  function updateControls() {
    const enabled = isEnabled();

    document.querySelectorAll('[data-notification-sound-toggle]').forEach(function (toggle) {
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
        icon.setAttribute('class', enabled ? 'fas fa-volume-up me-1' : 'fas fa-volume-mute me-1');
      }
    });

    document.querySelectorAll('[data-notification-sound-volume]').forEach(function (input) {
      input.value = String(Math.round(volume() * 100));
      input.disabled = !enabled;
    });

    if (!enabled) {
      setStatus(config.messages?.needsActivation || '', false);
    } else if (unlocked) {
      const testedAt = Number(storedValue(lastTestStorageKey));
      const lastTest = Number.isFinite(testedAt) && testedAt > 0
        ? String(config.messages?.lastTest || '').replace(':time', new Date(testedAt).toLocaleString())
        : '';
      setStatus([config.messages?.ready || '', lastTest].filter(Boolean).join(' '), false);
    }
  }

  function stopActiveAudio() {
    if (!activeAudio) {
      return;
    }

    activeAudio.pause();
    activeAudio.currentTime = 0;
    activeAudio = null;
  }

  function play(key, options) {
    const force = options?.force === true;

    if (!isEnabled() || (!unlocked && !force)) {
      if (isEnabled() && !unlocked) {
        setStatus(config.messages?.needsActivation || '', false);
      }

      return Promise.resolve(false);
    }

    const now = Date.now();

    if (!force && now - lastPlayedAt < throttleMs) {
      return Promise.resolve(false);
    }

    const audio = audioFor(key || 'action');

    if (!audio) {
      return Promise.resolve(false);
    }

    stopActiveAudio();
    activeAudio = audio;
    lastPlayedAt = now;
    audio.volume = volume();
    audio.currentTime = 0;

    const result = audio.play();

    if (!result || typeof result.then !== 'function') {
      unlocked = true;
      updateControls();

      return Promise.resolve(true);
    }

    return result.then(function () {
      unlocked = true;
      updateControls();
      return true;
    }).catch(function () {
      unlocked = false;
      setStatus(config.messages?.blocked || '', true);
      return false;
    });
  }

  function setEnabled(enabled) {
    storeValue(enabledStorageKey, enabled ? '1' : '0');

    if (!enabled) {
      stopActiveAudio();
      unlocked = false;
      updateControls();
      return Promise.resolve(false);
    }

    updateControls();
    return play('chat', { force: true });
  }

  function setVolume(value) {
    const nextVolume = clamp(Number(value));
    storeValue(volumeStorageKey, nextVolume);
    audioByKey.forEach(function (audio) {
      audio.volume = nextVolume;
    });
    updateControls();
  }

  function test(key) {
    storeValue(enabledStorageKey, '1');
    updateControls();
    return play(key || 'action', { force: true }).then(function (played) {
      if (played) {
        storeValue(lastTestStorageKey, Date.now());
        updateControls();
      }

      return played;
    });
  }

  function unlockAfterInteraction() {
    if (!isEnabled() || unlocked) {
      return;
    }

    const audio = audioFor('chat');

    if (!audio) {
      return;
    }

    audio.volume = 0;
    const result = audio.play();

    if (!result || typeof result.then !== 'function') {
      audio.pause();
      audio.currentTime = 0;
      audio.volume = volume();
      unlocked = true;
      updateControls();
      return;
    }

    result.then(function () {
      audio.pause();
      audio.currentTime = 0;
      audio.volume = volume();
      unlocked = true;
      updateControls();
    }).catch(function () {
      audio.volume = volume();
    });
  }

  document.addEventListener('click', function (event) {
    const toggle = event.target.closest('[data-notification-sound-toggle]');
    const testButton = event.target.closest('[data-notification-sound-test]');

    if (toggle) {
      event.preventDefault();
      event.stopPropagation();
      setEnabled(!isEnabled());
      return;
    }

    if (testButton) {
      event.preventDefault();
      test(testButton.getAttribute('data-notification-sound-test'));
    }
  });

  document.addEventListener('input', function (event) {
    const input = event.target.closest('[data-notification-sound-volume]');

    if (input) {
      setVolume(Number(input.value) / 100);
    }
  });

  ['pointerdown', 'keydown', 'touchstart'].forEach(function (eventName) {
    document.addEventListener(eventName, unlockAfterInteraction, { once: true, passive: true });
  });

  window.AppNotificationSound = {
    isEnabled: isEnabled,
    play: play,
    setEnabled: setEnabled,
    setVolume: setVolume,
    test: test,
    updateToggles: updateControls
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', updateControls);
  } else {
    updateControls();
  }
})(window, document);
