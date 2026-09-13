(function (window) {
  'use strict';

  const defaultTimeout = 2000;
  const defaultMaximumAge = 300000;
  const defaultLocationCacheTtl = 7 * 24 * 60 * 60 * 1000;
  const defaultLocationPromptCooldown = 24 * 60 * 60 * 1000;
  const locationCacheKey = 'erp_login_location_cache';
  const sourceCached = 'browser_geolocation_cached';
  const sourceLive = 'browser_geolocation_live';

  function safeNumber(value) {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
  }

  function isoNow() {
    return new Date().toISOString();
  }

  function timestamp(value) {
    if (typeof value === 'number' && Number.isFinite(value)) {
      return value;
    }

    if (typeof value !== 'string' || value === '') {
      return null;
    }

    const parsed = Date.parse(value);

    return Number.isFinite(parsed) ? parsed : null;
  }

  function safeStorage() {
    try {
      const storage = window.localStorage;
      const testKey = '__erp_location_storage_test__';

      storage.setItem(testKey, testKey);
      storage.removeItem(testKey);

      return storage;
    } catch (error) {
      return null;
    }
  }

  function readLocationCache() {
    const storage = safeStorage();

    if (!storage) {
      return null;
    }

    try {
      const cache = JSON.parse(storage.getItem(locationCacheKey) || 'null');

      return cache && typeof cache === 'object' ? cache : null;
    } catch (error) {
      return null;
    }
  }

  function writeLocationCache(cache) {
    const storage = safeStorage();

    if (!storage) {
      return;
    }

    try {
      storage.setItem(locationCacheKey, JSON.stringify(cache));
    } catch (error) {
      // Location capture is best-effort and must never block authentication.
    }
  }

  function hasCoordinates(location) {
    const latitude = safeNumber(location && location.latitude);
    const longitude = safeNumber(location && location.longitude);

    return latitude !== null && latitude >= -90 && latitude <= 90 && longitude !== null && longitude >= -180 && longitude <= 180;
  }

  function capturedAt(location) {
    if (!location) {
      return null;
    }

    return timestamp(location.captured_at) || timestamp(location.timestamp);
  }

  function cacheTtl(settings) {
    const ttl = Number.parseInt(settings.locationCacheTtl, 10);

    return Number.isFinite(ttl) && ttl > 0 ? ttl : defaultLocationCacheTtl;
  }

  function promptCooldown(settings) {
    const cooldown = Number.parseInt(settings.locationPromptCooldown, 10);

    return Number.isFinite(cooldown) && cooldown > 0 ? cooldown : defaultLocationPromptCooldown;
  }

  function isFreshCachedLocation(cache, settings) {
    const captured = capturedAt(cache);

    return hasCoordinates(cache) && captured !== null && Date.now() - captured <= cacheTtl(settings);
  }

  function cachedLocation(cache, extra) {
    const payload = {
      source: sourceCached,
      latitude: safeNumber(cache.latitude),
      longitude: safeNumber(cache.longitude),
      accuracy: safeNumber(cache.accuracy),
      altitude: safeNumber(cache.altitude),
      altitude_accuracy: safeNumber(cache.altitude_accuracy),
      heading: safeNumber(cache.heading),
      speed: safeNumber(cache.speed),
      timestamp: safeNumber(cache.timestamp),
      captured_at: cache.captured_at || null,
      permission_state: cache.permission_state || null,
      cache_expires_at: cache.cache_expires_at || null,
      cached: true
    };

    return Object.assign(payload, extra || {});
  }

  function rememberLocationAttempt(cache, update) {
    const nextCache = Object.assign({}, cache || {}, update, {
      last_request_at: isoNow()
    });

    writeLocationCache(nextCache);

    return nextCache;
  }

  function geolocationPermissionState() {
    const permissions = window.navigator && window.navigator.permissions;

    if (!permissions || typeof permissions.query !== 'function') {
      return Promise.resolve('unsupported');
    }

    return permissions.query({
      name: 'geolocation'
    }).then(function (status) {
      return status && typeof status.state === 'string' ? status.state : 'unknown';
    }).catch(function () {
      return 'unknown';
    });
  }

  function collectClientContext() {
    const navigatorObject = window.navigator || {};
    const screenObject = window.screen || {};
    const connection = navigatorObject.connection || navigatorObject.mozConnection || navigatorObject.webkitConnection || null;

    return {
      timezone: window.Intl && window.Intl.DateTimeFormat ? window.Intl.DateTimeFormat().resolvedOptions().timeZone || null : null,
      timezone_offset: new Date().getTimezoneOffset(),
      locale: navigatorObject.language || null,
      languages: Array.isArray(navigatorObject.languages) ? navigatorObject.languages.slice(0, 10) : [],
      platform: navigatorObject.platform || null,
      vendor: navigatorObject.vendor || null,
      user_agent: navigatorObject.userAgent || null,
      screen: {
        width: safeNumber(screenObject.width),
        height: safeNumber(screenObject.height),
        avail_width: safeNumber(screenObject.availWidth),
        avail_height: safeNumber(screenObject.availHeight),
        color_depth: safeNumber(screenObject.colorDepth),
        pixel_depth: safeNumber(screenObject.pixelDepth),
        pixel_ratio: safeNumber(window.devicePixelRatio)
      },
      window: {
        inner_width: safeNumber(window.innerWidth),
        inner_height: safeNumber(window.innerHeight)
      },
      hardware_concurrency: safeNumber(navigatorObject.hardwareConcurrency),
      device_memory: safeNumber(navigatorObject.deviceMemory),
      connection: connection ? {
        effective_type: connection.effectiveType || null,
        downlink: safeNumber(connection.downlink),
        rtt: safeNumber(connection.rtt),
        save_data: typeof connection.saveData === 'boolean' ? connection.saveData : null
      } : null
    };
  }

  function geolocationError(error, permissionState) {
    const code = error && error.code;

    return {
      source: sourceLive,
      denied: code === 1,
      unavailable: code === 2 || !code,
      timeout: code === 3,
      permission_state: permissionState || null,
      attempted_at: isoNow()
    };
  }

  function collectLiveLocation(settings, permissionState, cache) {
    const timeout = Number.parseInt(settings.timeout, 10) || defaultTimeout;
    const configuredMaximumAge = Number.parseInt(settings.maximumAge, 10);
    const maximumAge = Number.isFinite(configuredMaximumAge) && configuredMaximumAge >= 0 ? configuredMaximumAge : defaultMaximumAge;

    if (!window.navigator || !window.navigator.geolocation) {
      const unavailable = {
        source: sourceLive,
        unavailable: true,
        permission_state: 'unsupported',
        attempted_at: isoNow()
      };

      rememberLocationAttempt(cache, unavailable);

      return Promise.resolve(unavailable);
    }

    rememberLocationAttempt(cache, {
      permission_state: permissionState || null
    });

    return new Promise(function (resolve) {
      let settled = false;
      const timer = window.setTimeout(function () {
        if (settled) {
          return;
        }

        settled = true;

        const timeoutLocation = {
          source: sourceLive,
          timeout: true,
          permission_state: permissionState || null,
          attempted_at: isoNow()
        };

        rememberLocationAttempt(cache, timeoutLocation);
        resolve(timeoutLocation);
      }, timeout + 250);

      window.navigator.geolocation.getCurrentPosition(function (position) {
        if (settled) {
          return;
        }

        settled = true;
        window.clearTimeout(timer);

        const coords = position.coords || {};
        const captured = isoNow();
        const location = {
          source: sourceLive,
          latitude: safeNumber(coords.latitude),
          longitude: safeNumber(coords.longitude),
          accuracy: safeNumber(coords.accuracy),
          altitude: safeNumber(coords.altitude),
          altitude_accuracy: safeNumber(coords.altitudeAccuracy),
          heading: safeNumber(coords.heading),
          speed: safeNumber(coords.speed),
          timestamp: safeNumber(position.timestamp),
          captured_at: captured,
          permission_state: permissionState || 'granted',
          cache_expires_at: new Date(Date.parse(captured) + cacheTtl(settings)).toISOString()
        };

        if (hasCoordinates(location)) {
          writeLocationCache(location);
        } else {
          rememberLocationAttempt(cache, location);
        }

        resolve(location);
      }, function (error) {
        if (settled) {
          return;
        }

        settled = true;
        window.clearTimeout(timer);

        const errorLocation = geolocationError(error, permissionState);

        rememberLocationAttempt(cache, errorLocation);
        resolve(errorLocation);
      }, {
        enableHighAccuracy: settings.enableHighAccuracy === true,
        timeout: timeout,
        maximumAge: maximumAge
      });
    });
  }

  function shouldSkipLiveRequest(cache, settings, permissionState) {
    if (settings.forceLocationRefresh === true || permissionState === 'granted') {
      return false;
    }

    const lastRequestAt = timestamp(cache && cache.last_request_at);

    return lastRequestAt !== null && Date.now() - lastRequestAt < promptCooldown(settings);
  }

  function collectLocation(options) {
    const settings = options || {};
    const cache = readLocationCache();

    if (isFreshCachedLocation(cache, settings) && settings.forceLocationRefresh !== true) {
      return Promise.resolve(cachedLocation(cache));
    }

    return geolocationPermissionState().then(function (permissionState) {
      if (permissionState === 'denied') {
        rememberLocationAttempt(cache, {
          permission_state: permissionState,
          denied: true,
          attempted_at: isoNow()
        });

        if (hasCoordinates(cache) && settings.forceLocationRefresh !== true) {
          return cachedLocation(cache, {
            expired: !isFreshCachedLocation(cache, settings),
            permission_state: permissionState,
            skipped: true
          });
        }

        return {
          source: sourceLive,
          denied: true,
          permission_state: permissionState,
          attempted_at: isoNow()
        };
      }

      if (shouldSkipLiveRequest(cache, settings, permissionState)) {
        if (hasCoordinates(cache)) {
          return cachedLocation(cache, {
            expired: !isFreshCachedLocation(cache, settings),
            permission_state: permissionState,
            skipped: true
          });
        }

        return {
          source: sourceLive,
          unavailable: true,
          permission_state: permissionState,
          skipped: true,
          attempted_at: isoNow()
        };
      }

      return collectLiveLocation(settings, permissionState, cache);
    });
  }

  function collect(options) {
    const settings = options || {};
    const payload = {
      client_context: collectClientContext()
    };

    if (settings.includeLocation !== true) {
      return Promise.resolve(payload);
    }

    return collectLocation(settings).then(function (location) {
      payload.client_location = location;

      return payload;
    }).catch(function () {
      payload.client_location = {
        source: sourceLive,
        unavailable: true,
        attempted_at: isoNow()
      };

      return payload;
    });
  }

  window.AppClientContext = {
    collect: collect
  };
})(window);
