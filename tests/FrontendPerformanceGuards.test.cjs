const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { TextEncoder } = require('node:util');
const vm = require('node:vm');

function runBrowserScript(relativePath, context) {
    const filename = path.join(__dirname, '..', relativePath);

    vm.runInNewContext(readFileSync(filename, 'utf8'), context, { filename });
}

function listeners() {
    const registered = new Map();

    return {
        add(type, callback) {
            registered.set(type, [...(registered.get(type) || []), callback]);
        },
        emit(type, event = {}) {
            for (const callback of registered.get(type) || []) {
                callback(event);
            }
        },
    };
}

function deferredRequest() {
    const callbacks = { done: [], fail: [], always: [] };
    const request = {
        done(callback) {
            callbacks.done.push(callback);

            return request;
        },
        fail(callback) {
            callbacks.fail.push(callback);

            return request;
        },
        always(callback) {
            callbacks.always.push(callback);

            return request;
        },
        resolve(value) {
            callbacks.done.forEach((callback) => callback(value));
            callbacks.always.forEach((callback) => callback());
        },
        reject(value) {
            callbacks.fail.forEach((callback) => callback(value));
            callbacks.always.forEach((callback) => callback());
        },
    };

    return request;
}

function isMergeableObject(value) {
    return value !== null && Object.prototype.toString.call(value) === '[object Object]';
}

function cloneForExtend(value) {
    if (Array.isArray(value)) {
        return value.map(cloneForExtend);
    }

    if (isMergeableObject(value)) {
        return jqueryExtend(true, {}, value);
    }

    return value;
}

function jqueryExtend(...parameters) {
    let deep = false;
    let parameterIndex = 0;

    if (typeof parameters[0] === 'boolean') {
        deep = parameters[0];
        parameterIndex += 1;
    }

    const target = parameters[parameterIndex] || {};
    parameterIndex += 1;

    for (; parameterIndex < parameters.length; parameterIndex += 1) {
        const source = parameters[parameterIndex];

        if (!source) {
            continue;
        }

        for (const [key, value] of Object.entries(source)) {
            if (deep && Array.isArray(value)) {
                target[key] = value.map(cloneForExtend);
            } else if (deep && isMergeableObject(value)) {
                target[key] = jqueryExtend(
                    true,
                    isMergeableObject(target[key]) ? target[key] : {},
                    value,
                );
            } else {
                target[key] = value;
            }
        }
    }

    return target;
}

function dataTablesHarness() {
    const document = { kind: 'document' };
    const card = { kind: 'card' };
    const container = { card, kind: 'container' };
    const bindingCalls = [];
    const findCalls = [];
    const shortcutRoots = [];

    function wrapper(nodes) {
        const api = {
            __dataTablesHarnessWrapper: true,
            nodes,
            length: nodes.length,
            addClass() {
                return api;
            },
            closest(selector) {
                assert.equal(selector, '.erp-datatable-card');

                const matches = nodes
                    .map((node) => node.kind === 'card' ? node : node.card)
                    .filter(Boolean);

                return wrapper(matches);
            },
            find(selector) {
                nodes.forEach((node) => findCalls.push({ node, selector }));

                return wrapper([]);
            },
            get(index) {
                return nodes[index];
            },
            is(selector) {
                assert.equal(selector, '.erp-datatable-card');

                return nodes.some((node) => node.kind === 'card');
            },
            off(eventName, selector) {
                if (nodes.includes(document)) {
                    bindingCalls.push({ eventName, method: 'off', selector });
                }

                return api;
            },
            on(eventName, selector) {
                if (nodes.includes(document)) {
                    bindingCalls.push({ eventName, method: 'on', selector });
                }

                return api;
            },
            removeClass() {
                return api;
            },
        };

        return api;
    }

    const jQuery = (value) => {
        if (value && value.__dataTablesHarnessWrapper) {
            return value;
        }

        return wrapper(value === undefined || value === null ? [] : [value]);
    };

    jQuery.extend = jqueryExtend;

    const window = {
        addEventListener() {},
        AppShortcuts: {
            applyDataTableSearchTitles(root) {
                shortcutRoots.push(root);
            },
        },
        dataTableTranslations: {},
    };

    runBrowserScript('public/assets/js/modules/Core/datatables-defaults.js', {
        document,
        jQuery,
        window,
    });

    return { bindingCalls, card, container, document, findCalls, shortcutRoots, window };
}

function notificationPayload(unreadCount, sessionIdentity = 'shared-notification-identity') {
    return {
        data: {
            notifications: Array.from({ length: unreadCount }, (_, index) => ({
                body: `Body ${index + 1}`,
                category: 'task',
                id: String(index + 1),
                is_read: false,
                time: 'now',
                title: `Notification ${index + 1}`,
                url: '#',
            })),
            session_identity: sessionIdentity,
            unread_count: unreadCount,
        },
    };
}

function notificationsTabsHarness({ broadcastChannel = true, storage = true } = {}) {
    let currentTime = 1_000_000;
    let nextTimerId = 0;
    let nextTabId = 0;
    const timers = new Map();
    const tabs = [];
    const storageValues = new Map();
    const channels = new Map();
    const fetchCalls = [];
    const unreadCounts = [1, 2, 3];
    let nextPollIdentity = null;

    async function flushPromises() {
        for (let iteration = 0; iteration < 8; iteration += 1) {
            await Promise.resolve();
        }
    }

    const clock = {
        clearTimeout(timerId) {
            timers.delete(timerId);
        },
        async advance(milliseconds) {
            const targetTime = currentTime + milliseconds;

            while (true) {
                const nextTimer = [...timers.entries()]
                    .filter(([, timer]) => timer.runAt <= targetTime)
                    .sort((left, right) => left[1].runAt - right[1].runAt || left[0] - right[0])[0];

                if (!nextTimer) {
                    currentTime = targetTime;
                    await flushPromises();

                    const hasDueTimer = [...timers.values()].some((timer) => timer.runAt <= targetTime);

                    if (!hasDueTimer) {
                        break;
                    }

                    continue;
                }

                timers.delete(nextTimer[0]);
                currentTime = nextTimer[1].runAt;
                nextTimer[1].callback();
                await flushPromises();
            }
        },
        now() {
            return currentTime;
        },
        setTimeout(callback, delay = 0) {
            nextTimerId += 1;
            timers.set(nextTimerId, {
                callback,
                runAt: currentTime + Number(delay || 0),
            });

            return nextTimerId;
        },
    };

    class FakeDate extends Date {
        static now() {
            return clock.now();
        }
    }

    function dispatchStorage(sourceTab, key, oldValue, newValue) {
        tabs.filter((tab) => tab !== sourceTab).forEach((tab) => {
            clock.setTimeout(() => {
                tab.windowEvents.emit('storage', {
                    key,
                    newValue,
                    oldValue,
                    storageArea: tab.window.localStorage,
                });
            }, 0);
        });
    }

    function localStorageFor(tab) {
        if (!storage) {
            return undefined;
        }

        return {
            getItem(key) {
                return storageValues.has(key) ? storageValues.get(key) : null;
            },
            removeItem(key) {
                const oldValue = storageValues.has(key) ? storageValues.get(key) : null;
                storageValues.delete(key);
                dispatchStorage(tab, key, oldValue, null);
            },
            setItem(key, value) {
                const stringValue = String(value);
                const oldValue = storageValues.has(key) ? storageValues.get(key) : null;
                storageValues.set(key, stringValue);
                dispatchStorage(tab, key, oldValue, stringValue);
            },
        };
    }

    function broadcastChannelFor(tab) {
        return function FakeBroadcastChannel(name) {
            this.name = name;
            this.onmessage = null;

            const namedChannels = channels.get(name) || [];
            namedChannels.push(this);
            channels.set(name, namedChannels);

            this.close = () => {
                channels.set(name, (channels.get(name) || []).filter((channel) => channel !== this));
            };
            this.postMessage = (message) => {
                (channels.get(name) || [])
                    .filter((channel) => channel !== this)
                    .forEach((channel) => {
                        clock.setTimeout(() => {
                            if (typeof channel.onmessage === 'function') {
                                channel.onmessage({ data: JSON.parse(JSON.stringify(message)) });
                            }
                        }, 0);
                    });
            };
        };
    }

    function escapedElement() {
        let text = '';

        return {
            get innerHTML() {
                return text
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
            },
            set textContent(value) {
                text = String(value || '');
            },
        };
    }

    function createTab(name, coordinationIdentity = 'shared-notification-identity') {
        nextTabId += 1;

        const windowEvents = listeners();
        const documentEvents = listeners();
        const rootEvents = listeners();
        const countElement = {
            classList: {
                hidden: true,
                toggle(className, force) {
                    assert.equal(className, 'd-none');
                    countElement.classList.hidden = force;
                },
            },
            textContent: '0',
        };
        const listElement = { innerHTML: '' };
        const root = {
            addEventListener: (type, callback) => rootEvents.add(type, callback),
            querySelector(selector) {
                if (selector === '[data-notifications-count]') {
                    return countElement;
                }

                if (selector === '[data-notifications-list]') {
                    return listElement;
                }

                if (selector === '[data-notifications-read-all]') {
                    return null;
                }

                throw new Error(`Unexpected notifications root selector: ${selector}`);
            },
        };
        const csrfMeta = {
            getAttribute(attribute) {
                assert.equal(attribute, 'content');

                return 'csrf-token';
            },
        };
        const document = {
            hidden: false,
            addEventListener: (type, callback) => documentEvents.add(type, callback),
            createElement: escapedElement,
            querySelector(selector) {
                if (selector === '[data-notifications-root]') {
                    return root;
                }

                if (selector === 'meta[name="csrf-token"]') {
                    return csrfMeta;
                }

                throw new Error(`Unexpected document selector: ${selector}`);
            },
        };
        const tab = {
            countElement,
            document,
            documentEvents,
            listElement,
            name,
            navigationCount: 0,
            rootEvents,
            soundPlays: 0,
            window: null,
            windowEvents,
        };
        let currentHref = 'https://erp.test/dashboard';
        const location = {};

        Object.defineProperty(location, 'href', {
            get: () => currentHref,
            set(value) {
                currentHref = value;
                tab.navigationCount += 1;
            },
        });

        const window = {
            AppNotificationSound: {
                play() {
                    tab.soundPlays += 1;
                },
            },
            AppNotifications: {
                coordinationIdentity,
                hiddenIntervalMs: 120000,
                intervalMs: 45000,
                jitterMaxMs: 10000,
                jitterMinMs: 3000,
                messages: { empty: 'No notifications' },
                pollUrl: '/admin/notifications/poll',
                readAllUrl: '/admin/notifications/read-all',
                readUrl: '/admin/notifications/__NOTIFICATION__',
            },
            addEventListener: (type, callback) => windowEvents.add(type, callback),
            clearTimeout: (timerId) => clock.clearTimeout(timerId),
            crypto: {
                randomUUID: () => `notifications-${name}-${nextTabId}`,
            },
            fetch(url, options) {
                const pollNumber = fetchCalls.filter((call) => call.url === '/admin/notifications/poll').length;
                fetchCalls.push({ name, options, url });
                const responseIdentity = nextPollIdentity || coordinationIdentity;

                nextPollIdentity = null;

                return Promise.resolve({
                    json: () => Promise.resolve(notificationPayload(
                        unreadCounts[Math.min(pollNumber, unreadCounts.length - 1)],
                        responseIdentity,
                    )),
                    ok: true,
                });
            },
            localStorage: null,
            location,
            setTimeout: (callback, delay) => clock.setTimeout(callback, delay),
        };

        tab.window = window;
        window.localStorage = localStorageFor(tab);

        if (broadcastChannel) {
            window.BroadcastChannel = broadcastChannelFor(tab);
        }

        tabs.push(tab);

        runBrowserScript('public/assets/js/modules/Core/notifications.js', {
            Date: FakeDate,
            document,
            encodeURIComponent,
            Math: Object.assign(Object.create(Math), { random: () => 0 }),
            window,
        });

        return tab;
    }

    function pollCalls() {
        return fetchCalls.filter((call) => call.url === '/admin/notifications/poll');
    }

    function injectCoordinationMessage(namespaceIdentity, message) {
        const namespace = `erp-notifications:${namespaceIdentity}:/admin/notifications/poll`;
        const envelope = JSON.parse(JSON.stringify(message));

        if (broadcastChannel) {
            (channels.get(`${namespace}:channel`) || []).forEach((channel) => {
                clock.setTimeout(() => {
                    if (typeof channel.onmessage === 'function') {
                        channel.onmessage({ data: envelope });
                    }
                }, 0);
            });

            return;
        }

        tabs.forEach((tab) => {
            clock.setTimeout(() => {
                tab.windowEvents.emit('storage', {
                    key: `${namespace}:message`,
                    newValue: JSON.stringify(envelope),
                    oldValue: null,
                    storageArea: tab.window.localStorage,
                });
            }, 0);
        });
    }

    return {
        clock,
        createTab,
        fetchCalls,
        injectCoordinationMessage,
        pollCalls,
        respondToNextPollAs(identity) {
            nextPollIdentity = identity;
        },
        tabs,
    };
}

function pushNotificationsHarness() {
    const storageValues = new Map();
    const fetchCalls = [];
    let currentTime = 1_000_000;

    class FakeDate extends Date {
        static now() {
            return currentTime;
        }
    }

    async function flushAsyncWork() {
        for (let iteration = 0; iteration < 8; iteration += 1) {
            await Promise.resolve();
        }

        await new Promise((resolve) => setImmediate(resolve));

        for (let iteration = 0; iteration < 8; iteration += 1) {
            await Promise.resolve();
        }
    }

    function createSubscription({
        auth = 'auth-key-a',
        endpoint = 'https://push.example.test/subscription-a',
        p256dh = 'p256dh-key-a',
    } = {}) {
        const currentSubscription = {
            endpoint,
            unsubscribeCount: 0,
            async unsubscribe() {
                currentSubscription.unsubscribeCount += 1;

                return true;
            },
            toJSON() {
                return {
                    endpoint,
                    keys: { auth, p256dh },
                };
            },
        };

        return currentSubscription;
    }

    function stateKey(coordinationIdentity) {
        return `erp-push-notifications:${coordinationIdentity}:subscription-sync`;
    }

    function createNavigation({
        contentEncoding = 'aes128gcm',
        coordinationIdentity = 'opaque-user-a',
        currentSubscription = null,
        destroySucceeds = true,
        publicKey = 'vapid-public-key-a',
        storeSucceeds = true,
        subscriptionToCreate = currentSubscription,
        ttlMilliseconds = 86400000,
    } = {}) {
        const windowEvents = listeners();
        const toggleEvents = listeners();
        let loadHandler = null;
        const icon = { setAttribute() {} };
        const label = { textContent: '' };
        const status = {
            classList: { toggle() {} },
            textContent: '',
        };
        const toggle = {
            disabled: false,
            addEventListener: (type, callback) => toggleEvents.add(type, callback),
            querySelector(selector) {
                if (selector === '[data-push-notification-icon]') {
                    return icon;
                }

                if (selector === '[data-push-notification-label]') {
                    return label;
                }

                throw new Error(`Unexpected push toggle selector: ${selector}`);
            },
            setAttribute() {},
        };
        const csrfMeta = {
            getAttribute(attribute) {
                assert.equal(attribute, 'content');

                return 'csrf-token';
            },
        };
        const document = {
            querySelector(selector) {
                assert.equal(selector, 'meta[name="csrf-token"]');

                return csrfMeta;
            },
            querySelectorAll(selector) {
                if (selector === '[data-push-notification-toggle]') {
                    return [toggle];
                }

                if (selector === '[data-push-notification-status]') {
                    return [status];
                }

                throw new Error(`Unexpected push document selector: ${selector}`);
            },
        };
        const registration = {
            pushManager: {
                getSubscription: async () => currentSubscription,
                subscribe: async () => subscriptionToCreate,
            },
        };
        const serviceWorker = {
            addEventListener() {},
            ready: Promise.resolve(registration),
        };
        const localStorage = {
            getItem(key) {
                return storageValues.has(key) ? storageValues.get(key) : null;
            },
            removeItem(key) {
                storageValues.delete(key);
            },
            setItem(key, value) {
                storageValues.set(key, String(value));
            },
        };
        const window = {
            AppPushNotifications: {
                coordinationIdentity,
                coordinationTtlMs: ttlMilliseconds,
                destroyUrl: '/admin/notifications/push-subscriptions',
                enabled: true,
                messages: {
                    denied: 'Denied',
                    disable: 'Disable',
                    disabled: 'Disabled',
                    enable: 'Enable',
                    enabled: 'Enabled',
                    failed: 'Failed',
                    unavailable: 'Unavailable',
                },
                publicKey,
                storeUrl: '/admin/notifications/push-subscriptions',
            },
            Notification: {
                requestPermission: async () => 'granted',
            },
            PushManager: {
                supportedContentEncodings: [contentEncoding],
            },
            addEventListener(type, callback) {
                windowEvents.add(type, callback);

                if (type === 'load') {
                    loadHandler = callback;
                }
            },
            atob: () => '',
            crypto: webcrypto,
            fetch(url, options) {
                fetchCalls.push({ coordinationIdentity, options, url });

                const succeeds = options.method === 'DELETE' ? destroySucceeds : storeSucceeds;

                return Promise.resolve({
                    json: () => Promise.resolve({ success: succeeds }),
                    ok: succeeds,
                });
            },
            localStorage,
            navigator: { serviceWorker },
            TextEncoder,
        };

        runBrowserScript('public/assets/js/modules/Core/push-notifications.js', {
            Date: FakeDate,
            document,
            window,
        });

        return {
            async clickToggle() {
                toggleEvents.emit('click', {
                    preventDefault() {},
                    stopPropagation() {},
                });

                for (let iteration = 0; iteration < 20 && toggle.disabled; iteration += 1) {
                    await flushAsyncWork();
                }

                assert.equal(toggle.disabled, false);
            },
            async initialize() {
                assert.equal(typeof loadHandler, 'function');
                await loadHandler();
                await flushAsyncWork();
            },
        };
    }

    return {
        advanceTime(milliseconds) {
            currentTime += milliseconds;
        },
        createNavigation,
        createSubscription,
        fetchCalls,
        stateKey,
        storageValues,
    };
}

test('static legacy service worker retires root registrations without taking control', () => {
    const script = readFileSync(path.join(__dirname, '..', 'public/service-worker.js'), 'utf8');

    assert.match(script, /self\.skipWaiting\(\)/);
    assert.match(script, /self\.registration\.unregister\(\)/);
    assert.doesNotMatch(script, /clients\.claim\(\)/);
    assert.doesNotMatch(script, /location\.reload/);
    assert.doesNotMatch(script, /addEventListener\(['"]fetch['"]/);
});

test('datatable defaults cap page size and repair legacy state while preserving callbacks', () => {
    const { window } = dataTablesHarness();
    const defaults = window.AppDataTables.defaults();
    const legacyState = { length: 300 };

    assert.deepEqual(Array.from(window.AppDataTables.lengthMenuValues), [10, 25, 50, 75, 100]);
    assert.deepEqual(Array.from(defaults.lengthMenu[0]), [10, 25, 50, 75, 100]);
    assert.equal(defaults.autoWidth, false);
    assert.equal(defaults.orderClasses, false);
    assert.equal(defaults.processing, true);
    assert.equal(defaults.searchDelay, 400);

    defaults.stateLoadParams({}, legacyState);
    assert.equal(legacyState.length, 100);

    let callbackContext;
    let loadedLength;
    let savedLength;
    const options = window.AppDataTables.options({
        pageLength: 300,
        stateLoadParams(settings, data) {
            callbackContext = this;
            loadedLength = data.length;

            return false;
        },
        stateSaveParams(settings, data) {
            savedLength = data.length;
        },
    });
    const expectedContext = { table: 'inventory' };
    const loadState = { length: -1 };
    const saveState = { length: 300 };

    assert.equal(options.stateLoadParams.call(expectedContext, {}, loadState), false);
    options.stateSaveParams({}, saveState);

    assert.equal(callbackContext, expectedContext);
    assert.equal(options.pageLength, 100);
    assert.equal(loadedLength, 100);
    assert.equal(savedLength, 100);
    assert.equal(loadState.length, 100);
    assert.equal(saveState.length, 100);
});

test('datatable draw enhancements stay scoped to the current card and bind delegation once', () => {
    const harness = dataTablesHarness();
    const drawCallback = harness.window.AppDataTables.defaults().drawCallback;
    const callbackContext = {
        api() {
            return {
                table() {
                    return {
                        container() {
                            return harness.container;
                        },
                    };
                },
            };
        },
    };

    drawCallback.call(callbackContext);

    assert.equal(harness.findCalls.length, 3);
    assert.ok(harness.findCalls.every(({ node }) => node === harness.card));
    assert.ok(harness.findCalls.every(({ selector }) => !selector.includes('.erp-datatable-card')));
    assert.deepEqual(harness.shortcutRoots, [harness.card]);

    const firstDrawBindingCount = harness.bindingCalls.length;
    assert.equal(firstDrawBindingCount, 8);
    assert.ok(harness.bindingCalls.some(({ eventName, method, selector }) => (
        eventName === 'click.erpDataTableSelectAll'
        && method === 'on'
        && selector === '.erp-datatable-card thead th.dt-select'
    )));

    drawCallback.call(callbackContext);

    assert.equal(harness.bindingCalls.length, firstDrawBindingCount);
    assert.deepEqual(harness.shortcutRoots, [harness.card, harness.card]);
});

for (const broadcastChannel of [true, false]) {
    const transport = broadcastChannel ? 'BroadcastChannel' : 'storage events';
    const failoverTrigger = broadcastChannel ? 'a hidden leader' : 'a closing leader';

    test(`notification tabs share one poll and fail over from ${failoverTrigger} through ${transport}`, async () => {
        const harness = notificationsTabsHarness({ broadcastChannel });
        const firstTab = harness.createTab('first');
        const secondTab = harness.createTab('second');

        await harness.clock.advance(500);

        assert.equal(harness.pollCalls().length, 1);
        assert.equal(firstTab.countElement.textContent, '1');
        assert.equal(secondTab.countElement.textContent, '1');
        assert.match(firstTab.listElement.innerHTML, /Notification 1/);
        assert.match(secondTab.listElement.innerHTML, /Notification 1/);

        const firstLeaderName = harness.pollCalls()[0].name;
        const firstLeader = harness.tabs.find((tab) => tab.name === firstLeaderName);
        const follower = harness.tabs.find((tab) => tab !== firstLeader);

        if (broadcastChannel) {
            firstLeader.document.hidden = true;
            firstLeader.documentEvents.emit('visibilitychange');
        } else {
            firstLeader.windowEvents.emit('pagehide');
        }

        await harness.clock.advance(1000);

        assert.equal(harness.pollCalls().length, 2);
        assert.equal(harness.pollCalls()[1].name, follower.name);
        assert.equal(follower.countElement.textContent, '2');
        assert.match(follower.listElement.innerHTML, /Notification 2/);

        if (broadcastChannel) {
            assert.equal(firstLeader.countElement.textContent, '2');
            assert.match(firstLeader.listElement.innerHTML, /Notification 2/);
        }

        assert.equal(firstTab.soundPlays + secondTab.soundPlays, 1);
    });

    test(`notification coordination isolates authenticated identities through ${transport}`, async () => {
        const harness = notificationsTabsHarness({ broadcastChannel });
        const firstIdentity = 'opaque-user-alice';
        const secondIdentity = 'opaque-user-bob';
        const firstTab = harness.createTab('alice', firstIdentity);
        const secondTab = harness.createTab('bob', secondIdentity);

        await harness.clock.advance(500);

        assert.equal(harness.pollCalls().length, 2);
        assert.equal(firstTab.countElement.textContent, '1');
        assert.equal(secondTab.countElement.textContent, '2');
        assert.match(firstTab.listElement.innerHTML, /Notification 1/);
        assert.match(secondTab.listElement.innerHTML, /Notification 2/);

        harness.injectCoordinationMessage(firstIdentity, {
            identity: secondIdentity,
            payload: notificationPayload(3),
            source: 'other-authenticated-user',
            type: 'payload',
        });
        await harness.clock.advance(0);

        assert.equal(firstTab.countElement.textContent, '1');
        assert.match(firstTab.listElement.innerHTML, /Notification 1/);
        assert.doesNotMatch(firstTab.listElement.innerHTML, /Notification 3/);
    });
}

test('notification polling stays single-flight when cross-tab storage is unavailable', async () => {
    const harness = notificationsTabsHarness({ broadcastChannel: false, storage: false });
    const tab = harness.createTab('legacy');

    await harness.clock.advance(0);

    assert.equal(harness.pollCalls().length, 1);
    assert.equal(tab.countElement.textContent, '1');

    tab.window.AppNotificationsClient.refresh();
    tab.window.AppNotificationsClient.refresh();
    await harness.clock.advance(0);

    assert.equal(harness.pollCalls().length, 2);
    assert.equal(tab.countElement.textContent, '2');
});

test('notification polling rejects a response from a different authenticated session', async () => {
    const harness = notificationsTabsHarness();
    const tab = harness.createTab('stale-session');

    await harness.clock.advance(500);

    assert.equal(tab.countElement.textContent, '1');

    harness.respondToNextPollAs('different-authenticated-session');
    tab.window.AppNotificationsClient.refresh();
    await harness.clock.advance(0);

    assert.equal(tab.navigationCount, 1);
    assert.equal(tab.countElement.textContent, '1');
    assert.doesNotMatch(tab.listElement.innerHTML, /Notification 2/);
});

test('back-forward cache restore stays hidden until its revalidation handler unlocks it', () => {
    const windowEvents = listeners();
    const dispatchedEvents = [];
    const rootAttributes = new Map();
    const password = { value: 'secret' };
    const pageRoot = {
        removeAttribute: (name) => rootAttributes.delete(name),
        setAttribute: (name, value) => rootAttributes.set(name, value),
        style: {
            pointerEvents: 'auto',
            visibility: 'visible',
        },
    };
    let currentHref = 'https://erp.test/dashboard';
    let navigationCount = 0;
    const location = {};

    Object.defineProperty(location, 'href', {
        get: () => currentHref,
        set(value) {
            currentHref = value;
            navigationCount += 1;
        },
    });

    const window = {
        addEventListener: (type, callback) => windowEvents.add(type, callback),
        dispatchEvent(event) {
            dispatchedEvents.push(event.type);
            windowEvents.emit(event.type, event);
        },
        location,
        performance: {
            getEntriesByType: () => [{ type: 'navigate' }],
        },
    };
    const document = {
        documentElement: pageRoot,
        querySelectorAll(selector) {
            assert.equal(selector, 'input[type="password"]');

            return [password];
        },
    };

    runBrowserScript('public/assets/js/modules/Core/page-cache-guard.js', {
        document,
        Event: class Event {
            constructor(type) {
                this.type = type;
            }
        },
        window,
    });

    windowEvents.add('erp:bfcache-restore', () => window.AppPageCacheGuard.claimRestore());

    windowEvents.emit('pagehide', { persisted: true });

    assert.equal(window.AppPageCacheGuard.isLocked(), true);
    assert.equal(pageRoot.style.pointerEvents, 'none');
    assert.equal(pageRoot.style.visibility, 'hidden');
    assert.equal(rootAttributes.get('data-erp-bfcache-locked'), 'true');

    windowEvents.emit('pageshow', { persisted: true });

    assert.equal(password.value, '');
    assert.deepEqual(dispatchedEvents, ['erp:bfcache-restore']);
    assert.equal(navigationCount, 0);
    assert.equal(window.AppPageCacheGuard.isLocked(), true);
    assert.equal(pageRoot.style.visibility, 'hidden');

    window.AppPageCacheGuard.unlock();

    assert.equal(window.AppPageCacheGuard.isLocked(), false);
    assert.equal(pageRoot.style.pointerEvents, 'auto');
    assert.equal(pageRoot.style.visibility, 'visible');
    assert.equal(rootAttributes.has('data-erp-bfcache-locked'), false);
});

test('an unclaimed back-forward cache restore navigates once while remaining locked', () => {
    const windowEvents = listeners();
    const pageRoot = {
        removeAttribute() {},
        setAttribute() {},
        style: {
            pointerEvents: '',
            visibility: '',
        },
    };
    let currentHref = 'https://erp.test/login';
    let navigationCount = 0;
    const location = {};

    Object.defineProperty(location, 'href', {
        get: () => currentHref,
        set(value) {
            currentHref = value;
            navigationCount += 1;
        },
    });

    const window = {
        addEventListener: (type, callback) => windowEvents.add(type, callback),
        dispatchEvent: (event) => windowEvents.emit(event.type, event),
        location,
        performance: {
            getEntriesByType: () => [{ type: 'navigate' }],
        },
    };
    const document = {
        documentElement: pageRoot,
        querySelectorAll: () => [],
    };

    runBrowserScript('public/assets/js/modules/Core/page-cache-guard.js', {
        document,
        Event: class Event {
            constructor(type) {
                this.type = type;
            }
        },
        window,
    });

    windowEvents.emit('pagehide', { persisted: true });
    windowEvents.emit('pageshow', { persisted: true });
    windowEvents.emit('pageshow', { persisted: true });

    assert.equal(navigationCount, 1);
    assert.equal(window.AppPageCacheGuard.isLocked(), true);
    assert.equal(pageRoot.style.pointerEvents, 'none');
    assert.equal(pageRoot.style.visibility, 'hidden');
});

test('initial activity is throttled, focus only revalidates, and BFCache restore still touches', () => {
    const windowEvents = listeners();
    const documentEvents = listeners();
    const timers = new Map();
    const requests = [];
    let activityHandler = null;
    let nextTimerId = 0;
    let now = 1_000_000;
    const pageCacheGuard = {
        claimCount: 0,
        locked: false,
        unlockCount: 0,
        claimRestore() {
            pageCacheGuard.claimCount += 1;
            pageCacheGuard.locked = true;
        },
        unlock() {
            pageCacheGuard.unlockCount += 1;
            pageCacheGuard.locked = false;
        },
    };

    const window = {
        AppPageCacheGuard: pageCacheGuard,
        AppSession: {
            identity: 'current-session-identity',
            lifetimeSeconds: 7200,
            statusUrl: '/session/status',
            touchUrl: '/session/touch',
        },
        addEventListener: (type, callback) => windowEvents.add(type, callback),
        clearTimeout: (id) => timers.delete(id),
        location: {
            hash: '',
            href: 'https://erp.test/dashboard',
            pathname: '/dashboard',
            search: '',
        },
        setTimeout(callback, delay) {
            nextTimerId += 1;
            timers.set(nextTimerId, { callback, delay });

            return nextTimerId;
        },
    };
    const document = {
        addEventListener: (type, callback) => documentEvents.add(type, callback),
        hidden: false,
    };
    const documentApi = {
        ajaxError() {
            return documentApi;
        },
        on(events, callback) {
            assert.equal(events, 'pointerdown keydown scroll touchstart');
            activityHandler = callback;

            return documentApi;
        },
    };
    const jQuery = (value) => {
        if (value === document) {
            return documentApi;
        }

        return {
            attr: () => 'csrf-token',
        };
    };

    jQuery.ajax = (options) => {
        const deferred = deferredRequest();
        requests.push({ deferred, options });

        return deferred;
    };

    runBrowserScript('public/assets/js/modules/Core/session-timeout.js', {
        Date: { now: () => now },
        document,
        jQuery,
        window,
    });

    const runDebounceTimer = () => {
        const timer = [...timers.entries()].find(([, value]) => value.delay === 1000);
        assert.ok(timer, 'expected one pending status debounce timer');
        timers.delete(timer[0]);
        timer[1].callback();
    };
    const statusRequests = () => requests.filter(({ options }) => options.url === '/session/status');
    const touchRequests = () => requests.filter(({ options }) => options.url === '/session/touch');

    assert.equal(typeof activityHandler, 'function');
    activityHandler();

    assert.equal(touchRequests().length, 0);

    windowEvents.emit('focus');
    documentEvents.emit('visibilitychange');

    assert.equal([...timers.values()].filter(({ delay }) => delay === 1000).length, 1);
    runDebounceTimer();
    assert.equal(statusRequests().length, 1);

    windowEvents.emit('focus');
    documentEvents.emit('visibilitychange');

    assert.equal([...timers.values()].filter(({ delay }) => delay === 1000).length, 0);
    assert.equal(statusRequests().length, 1);

    now += 60001;
    statusRequests()[0].deferred.resolve({
        authenticated: true,
        expired: false,
        session_identity: 'current-session-identity',
        seconds_remaining: 7200,
    });

    assert.equal(touchRequests().length, 0);

    windowEvents.emit('focus');
    documentEvents.emit('visibilitychange');
    runDebounceTimer();

    assert.equal(statusRequests().length, 2);

    windowEvents.emit('erp:bfcache-restore');

    assert.equal(pageCacheGuard.claimCount, 1);
    assert.equal(pageCacheGuard.locked, true);
    assert.equal([...timers.values()].filter(({ delay }) => delay === 1000).length, 0);
    assert.equal(statusRequests().length, 3);

    statusRequests()[1].deferred.resolve({
        authenticated: true,
        expired: false,
        session_identity: 'current-session-identity',
        seconds_remaining: 7200,
    });

    assert.equal(pageCacheGuard.locked, true);
    assert.equal(pageCacheGuard.unlockCount, 0);
    assert.equal(touchRequests().length, 0);

    statusRequests()[2].deferred.resolve({
        authenticated: true,
        expired: false,
        session_identity: 'current-session-identity',
        seconds_remaining: 7200,
    });

    assert.equal(pageCacheGuard.locked, false);
    assert.equal(pageCacheGuard.unlockCount, 1);
    assert.equal(touchRequests().length, 1);
});

test('failed or unconfirmed BFCache status keeps the page locked and navigates at most once', () => {
    const scenarios = [
        { fail: true, response: { status: 0 } },
        { fail: true, response: { status: 503 } },
        { fail: false, response: { authenticated: true } },
        {
            fail: false,
            response: {
                authenticated: true,
                expired: false,
                session_identity: 'different-session-identity',
            },
        },
    ];

    for (const scenario of scenarios) {
        const windowEvents = listeners();
        const documentEvents = listeners();
        const timers = new Map();
        const requests = [];
        let currentHref = 'https://erp.test/dashboard';
        let navigationCount = 0;
        let nextTimerId = 0;
        const location = {
            assign(value) {
                currentHref = value;
                navigationCount += 1;
            },
            hash: '',
            pathname: '/dashboard',
            search: '',
        };
        const pageCacheGuard = {
            claimCount: 0,
            locked: true,
            unlockCount: 0,
            claimRestore() {
                pageCacheGuard.claimCount += 1;
                pageCacheGuard.locked = true;
            },
            unlock() {
                pageCacheGuard.unlockCount += 1;
                pageCacheGuard.locked = false;
            },
        };

        Object.defineProperty(location, 'href', {
            get: () => currentHref,
            set(value) {
                currentHref = value;
                navigationCount += 1;
            },
        });

        const window = {
            AppPageCacheGuard: pageCacheGuard,
            AppSession: {
                identity: 'current-session-identity',
                lifetimeSeconds: 7200,
                statusUrl: '/session/status',
                touchUrl: '/session/touch',
            },
            addEventListener: (type, callback) => windowEvents.add(type, callback),
            clearTimeout: (id) => timers.delete(id),
            location,
            setTimeout(callback, delay) {
                nextTimerId += 1;
                timers.set(nextTimerId, { callback, delay });

                return nextTimerId;
            },
        };
        const document = {
            addEventListener: (type, callback) => documentEvents.add(type, callback),
            hidden: false,
        };
        const documentApi = {
            ajaxError() {
                return documentApi;
            },
            on() {
                return documentApi;
            },
        };
        const jQuery = (value) => {
            if (value === document) {
                return documentApi;
            }

            return {
                attr: () => 'csrf-token',
            };
        };

        jQuery.ajax = (options) => {
            const deferred = deferredRequest();
            requests.push({ deferred, options });

            return deferred;
        };

        runBrowserScript('public/assets/js/modules/Core/session-timeout.js', {
            document,
            jQuery,
            window,
        });

        windowEvents.emit('erp:bfcache-restore');

        const statusRequests = requests.filter(({ options }) => options.url === '/session/status');
        assert.equal(pageCacheGuard.claimCount, 1);
        assert.equal(statusRequests.length, 1);
        assert.equal([...timers.values()].filter(({ delay }) => delay === 1000).length, 0);

        if (scenario.fail) {
            statusRequests[0].deferred.reject(scenario.response);
        } else {
            statusRequests[0].deferred.resolve(scenario.response);
        }

        assert.equal(pageCacheGuard.locked, true);
        assert.equal(pageCacheGuard.unlockCount, 0);
        assert.equal(navigationCount, 1);

        windowEvents.emit('erp:bfcache-restore');

        assert.equal(requests.filter(({ options }) => options.url === '/session/status').length, 1);
        assert.equal(navigationCount, 1);
    }
});

test('push subscription sync is user-scoped and invalidates on signature, key, encoding, or TTL changes', async () => {
    const harness = pushNotificationsHarness();
    const firstSubscription = harness.createSubscription();
    const changedSubscription = harness.createSubscription({ auth: 'auth-key-b' });
    const firstIdentity = 'opaque-user-a';
    const secondIdentity = 'opaque-user-b';
    const postCalls = () => harness.fetchCalls.filter(({ options }) => options.method === 'POST');

    await harness.createNavigation({
        coordinationIdentity: firstIdentity,
        currentSubscription: firstSubscription,
    }).initialize();

    assert.equal(postCalls().length, 1);
    assert.equal(harness.storageValues.has(harness.stateKey(firstIdentity)), true);
    assert.doesNotMatch(harness.storageValues.get(harness.stateKey(firstIdentity)), /subscription-a|auth-key-a|p256dh-key-a/);
    assert.match(JSON.parse(harness.storageValues.get(harness.stateKey(firstIdentity))).signature, /^[a-f0-9]{64}$/);

    await harness.createNavigation({
        coordinationIdentity: firstIdentity,
        currentSubscription: firstSubscription,
    }).initialize();

    assert.equal(postCalls().length, 1);

    await harness.createNavigation({
        coordinationIdentity: secondIdentity,
        currentSubscription: firstSubscription,
    }).initialize();

    assert.equal(postCalls().length, 2);
    assert.equal(harness.storageValues.has(harness.stateKey(secondIdentity)), true);

    await harness.createNavigation({
        coordinationIdentity: firstIdentity,
        currentSubscription: changedSubscription,
    }).initialize();

    assert.equal(postCalls().length, 3);

    await harness.createNavigation({
        coordinationIdentity: firstIdentity,
        currentSubscription: changedSubscription,
        publicKey: 'vapid-public-key-b',
    }).initialize();

    assert.equal(postCalls().length, 4);

    await harness.createNavigation({
        contentEncoding: 'aesgcm',
        coordinationIdentity: firstIdentity,
        currentSubscription: changedSubscription,
        publicKey: 'vapid-public-key-b',
    }).initialize();

    assert.equal(postCalls().length, 5);

    await harness.createNavigation({
        contentEncoding: 'aesgcm',
        coordinationIdentity: firstIdentity,
        currentSubscription: changedSubscription,
        publicKey: 'vapid-public-key-b',
    }).initialize();

    assert.equal(postCalls().length, 5);

    harness.advanceTime(86400001);

    await harness.createNavigation({
        contentEncoding: 'aesgcm',
        coordinationIdentity: firstIdentity,
        currentSubscription: changedSubscription,
        publicKey: 'vapid-public-key-b',
    }).initialize();

    assert.equal(postCalls().length, 6);
});

test('failed push sync retries and disabling clears state before a new subscription sync', async () => {
    const harness = pushNotificationsHarness();
    const subscription = harness.createSubscription();
    const retryIdentity = 'opaque-retry-user';
    const disableIdentity = 'opaque-disable-user';
    const postCalls = () => harness.fetchCalls.filter(({ options }) => options.method === 'POST');
    const deleteCalls = () => harness.fetchCalls.filter(({ options }) => options.method === 'DELETE');

    await harness.createNavigation({
        coordinationIdentity: retryIdentity,
        currentSubscription: subscription,
        storeSucceeds: false,
    }).initialize();

    assert.equal(postCalls().length, 1);
    assert.equal(harness.storageValues.has(harness.stateKey(retryIdentity)), false);

    await harness.createNavigation({
        coordinationIdentity: retryIdentity,
        currentSubscription: subscription,
    }).initialize();

    assert.equal(postCalls().length, 2);
    assert.equal(harness.storageValues.has(harness.stateKey(retryIdentity)), true);

    await harness.createNavigation({
        coordinationIdentity: disableIdentity,
        currentSubscription: subscription,
    }).initialize();

    assert.equal(postCalls().length, 3);
    assert.equal(harness.storageValues.has(harness.stateKey(disableIdentity)), true);

    const disableNavigation = harness.createNavigation({
        coordinationIdentity: disableIdentity,
        currentSubscription: subscription,
    });

    await disableNavigation.initialize();
    await disableNavigation.clickToggle();

    assert.equal(postCalls().length, 3);
    assert.equal(deleteCalls().length, 1);
    assert.equal(subscription.unsubscribeCount, 1);
    assert.equal(harness.storageValues.has(harness.stateKey(disableIdentity)), false);

    const replacementSubscription = harness.createSubscription();
    const enableNavigation = harness.createNavigation({
        coordinationIdentity: disableIdentity,
        currentSubscription: null,
        subscriptionToCreate: replacementSubscription,
    });

    await enableNavigation.initialize();
    await enableNavigation.clickToggle();

    assert.equal(postCalls().length, 4);
    assert.equal(harness.storageValues.has(harness.stateKey(disableIdentity)), true);
});

test('pwa runtime unregisters a legacy worker before registering the current worker', async () => {
    const windowEvents = listeners();
    const calls = [];
    const legacyRegistration = {
        active: { scriptURL: 'https://erp.test/service-worker.js' },
        installing: null,
        unregister: async () => {
            calls.push('unregister-legacy');
        },
        waiting: null,
    };
    const currentRegistration = {
        addEventListener() {},
        waiting: null,
    };
    const serviceWorker = {
        addEventListener() {},
        getRegistrations: async () => [legacyRegistration],
        register: async (url, options) => {
            calls.push(['register-current', url, options.scope]);

            return currentRegistration;
        },
    };
    const window = {
        AppPwaRuntime: {
            cachePrefix: 'erp-pwa-cache',
            enabled: true,
            scope: '/',
            serviceWorkerUrl: '/pwa-service-worker.js',
        },
        addEventListener: (type, callback) => windowEvents.add(type, callback),
        location: { href: 'https://erp.test/dashboard' },
        navigator: { serviceWorker },
    };
    const document = {
        querySelector: () => null,
    };

    runBrowserScript('public/assets/js/modules/Core/pwa-runtime.js', {
        document,
        Promise,
        URL,
        window,
    });

    windowEvents.emit('load');
    await new Promise((resolve) => setImmediate(resolve));

    assert.deepEqual(calls, [
        'unregister-legacy',
        ['register-current', '/pwa-service-worker.js', '/'],
    ]);
});
