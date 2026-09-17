const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function runBrowserScript(relativePath, context) {
    const filename = path.join(__dirname, '..', relativePath);

    vm.runInNewContext(readFileSync(filename, 'utf8'), context, { filename });
}

test('shared SweetAlert service follows the live ERP theme', async () => {
    const attributes = new Map([
        ['data-bs-theme', 'dark'],
        ['dir', 'rtl'],
    ]);
    const fired = [];
    let observerCallback = null;
    const documentElement = {
        getAttribute: (name) => attributes.get(name) || null,
        setAttribute: (name, value) => attributes.set(name, String(value)),
    };
    const document = {
        body: { getAttribute: () => null },
        documentElement,
        querySelector: () => null,
    };
    const window = {
        MutationObserver: class {
            constructor(callback) {
                observerCallback = callback;
            }

            observe() {}
        },
        Swal: {
            fire(options) {
                fired.push(options);

                return Promise.resolve({ isConfirmed: true });
            },
            isVisible: () => false,
            resumeTimer() {},
            stopTimer() {},
        },
    };

    runBrowserScript('public/assets/js/modules/Core/alerts.js', { document, Promise, window });

    assert.equal(attributes.get('data-swal2-theme'), 'dark');
    await window.AppAlerts.toast('info', 'New notification');
    assert.equal(fired[0].position, 'top-left');
    assert.match(fired[0].customClass.container, /erp-swal-toast-container/);
    assert.match(fired[0].customClass.popup, /erp-swal-toast-popup/);

    attributes.set('data-bs-theme', 'light');
    observerCallback([{ attributeName: 'data-bs-theme' }]);
    assert.equal(attributes.get('data-swal2-theme'), 'light');
    assert.equal(window.AppAlerts.theme(), 'light');
});

test('notification sound defaults on, unlocks after interaction, and respects an explicit mute', async () => {
    const storage = new Map();
    const listeners = new Map();
    let playCount = 0;

    class FakeAudio {
        constructor() {
            this.currentTime = 0;
            this.volume = 1;
        }

        pause() {}

        play() {
            playCount += 1;

            return Promise.resolve();
        }
    }

    const document = {
        addEventListener(type, callback) {
            listeners.set(type, callback);
        },
        querySelectorAll() {
            return [];
        },
        readyState: 'complete',
    };
    const window = {
        AppNotificationSoundConfig: {
            sources: { action: '/action.mp3', chat: '/chat.mp3', urgent: '/urgent.mp3' },
        },
        Audio: FakeAudio,
        localStorage: {
            getItem: (key) => storage.has(key) ? storage.get(key) : null,
            setItem: (key, value) => storage.set(key, String(value)),
        },
    };

    runBrowserScript('public/assets/js/modules/Core/notification-sound.js', {
        Audio: FakeAudio,
        Date,
        Map,
        Number,
        Promise,
        document,
        window,
    });

    assert.equal(window.AppNotificationSound.isEnabled(), true);
    assert.equal(await window.AppNotificationSound.play('action'), false);

    listeners.get('pointerdown')();
    await Promise.resolve();
    assert.equal(await window.AppNotificationSound.play('action'), true);
    assert.equal(playCount, 2);

    await window.AppNotificationSound.setEnabled(false);
    assert.equal(window.AppNotificationSound.isEnabled(), false);
    assert.equal(await window.AppNotificationSound.play('urgent'), false);
    assert.equal(playCount, 2);
});

test('unsupported device notifications stay explained in the notification center', async () => {
    const control = {
        hidden: false,
        hasAttribute: (name) => name === 'data-push-notification-explained',
    };
    const status = {
        classList: { toggle() {} },
        textContent: 'stale status',
    };
    const toggle = {
        addEventListener() {},
        disabled: false,
        querySelector: () => null,
        setAttribute() {},
    };
    let loadHandler = null;
    const document = {
        querySelector: () => ({ getAttribute: () => 'csrf-token' }),
        querySelectorAll(selector) {
            if (selector === '[data-push-notification-toggle]') return [toggle];
            if (selector === '[data-push-notification-control]') return [control];
            if (selector === '[data-push-notification-status]') return [status];
            throw new Error(`Unexpected selector: ${selector}`);
        },
    };
    const window = {
        AppPushNotifications: { enabled: false, messages: { unavailable: 'Device notifications are currently unavailable.' } },
        Notification: { permission: 'default', requestPermission: async () => 'default' },
        addEventListener(type, callback) {
            if (type === 'load') loadHandler = callback;
        },
        navigator: {},
    };

    runBrowserScript('public/assets/js/modules/Core/push-notifications.js', { Array, Boolean, document, window });

    assert.equal(control.hidden, false);
    assert.equal(status.textContent, 'Device notifications are currently unavailable.');
    await loadHandler();
    assert.equal(control.hidden, false);
    assert.equal(status.textContent, 'Device notifications are currently unavailable.');
    assert.equal(toggle.disabled, true);
});

test('blocked device notification permission is explained without requesting it again', async () => {
    let loadHandler = null;
    let clickHandler = null;
    let permissionRequests = 0;
    const control = {
        hidden: false,
        hasAttribute: () => false,
    };
    const status = {
        classList: { toggle() {} },
        textContent: '',
    };
    const toggle = {
        addEventListener(type, callback) {
            if (type === 'click') clickHandler = callback;
        },
        disabled: false,
        querySelector: () => null,
        setAttribute() {},
    };
    const serviceWorker = {
        addEventListener() {},
        ready: Promise.resolve({
            pushManager: {
                getSubscription: async () => null,
            },
        }),
    };
    const document = {
        querySelector: () => ({ getAttribute: () => 'csrf-token' }),
        querySelectorAll(selector) {
            if (selector === '[data-push-notification-toggle]') return [toggle];
            if (selector === '[data-push-notification-control]') return [control];
            if (selector === '[data-push-notification-status]') return [status];
            throw new Error(`Unexpected selector: ${selector}`);
        },
    };
    const window = {
        AppPushNotifications: {
            enabled: true,
            messages: { denied: 'Notifications are blocked for this site.', unavailable: 'Unavailable' },
            publicKey: 'public-key',
        },
        Notification: {
            permission: 'denied',
            requestPermission: async () => {
                permissionRequests += 1;

                return 'denied';
            },
        },
        addEventListener(type, callback) {
            if (type === 'load') loadHandler = callback;
        },
        localStorage: {
            getItem: () => null,
            removeItem() {},
            setItem() {},
        },
        navigator: { serviceWorker },
    };

    runBrowserScript('public/assets/js/modules/Core/push-notifications.js', { Array, Boolean, document, Promise, window });

    await loadHandler();
    await Promise.resolve();
    clickHandler({ preventDefault() {}, stopPropagation() {} });
    await Promise.resolve();

    assert.equal(control.hidden, false);
    assert.equal(toggle.disabled, true);
    assert.equal(status.textContent, 'Notifications are blocked for this site.');
    assert.equal(permissionRequests, 0);
});
