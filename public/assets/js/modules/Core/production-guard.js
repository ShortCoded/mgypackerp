(function () {
    'use strict';

    var blockedShiftCodes = {
        KeyC: true,
        KeyI: true,
        KeyJ: true
    };
    var blockedShiftKeys = {
        c: true,
        i: true,
        j: true
    };

    function normalizedKey(event) {
        return String(event.key || '').toLowerCase();
    }

    function isBlockedShiftCombo(event) {
        return event.shiftKey && isBlockedDevToolsKey(event);
    }

    function isBlockedDevToolsKey(event) {
        var key = normalizedKey(event);
        var code = String(event.code || '');

        return blockedShiftKeys[key] || blockedShiftCodes[code];
    }

    function isBlockedShortcut(event) {
        var key = normalizedKey(event);

        if (key === 'f12' || event.keyCode === 123) {
            return true;
        }

        if (event.ctrlKey && isBlockedShiftCombo(event)) {
            return true;
        }

        if (event.metaKey && event.altKey && isBlockedDevToolsKey(event)) {
            return true;
        }

        return event.ctrlKey && key === 'u';
    }

    function blockEvent(event) {
        event.preventDefault();
        event.stopPropagation();

        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
    }

    document.addEventListener('contextmenu', blockEvent, true);
    document.addEventListener('keydown', function (event) {
        if (isBlockedShortcut(event)) {
            blockEvent(event);
        }
    }, true);
})();
