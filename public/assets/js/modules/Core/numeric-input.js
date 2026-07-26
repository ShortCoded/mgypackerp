(function (window, document) {
    'use strict';

    var decimalPattern = /^-?(?:(?:\d+(?:\.\d*)?)|(?:\.\d+)|(?:[1-9]\d{0,2}(?:,\d{3})+(?:\.\d*)?))$/;
    var selector = '[data-numeric-input]';

    function decimalString(value) {
        if (value === null || value === undefined) {
            return '';
        }

        if (typeof value === 'number') {
            if (!Number.isFinite(value)) {
                return '';
            }

            return expandScientificNotation(String(value));
        }

        return String(value).trim();
    }

    function expandScientificNotation(value) {
        if (!/[eE]/.test(value)) {
            return value;
        }

        var match = String(value).match(/^(-?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/);

        if (!match) {
            return value;
        }

        var sign = match[1];
        var integer = match[2];
        var fraction = match[3] || '';
        var exponent = Number(match[4]);
        var digits = integer + fraction;
        var decimalPosition = integer.length + exponent;

        if (decimalPosition <= 0) {
            return sign + '0.' + '0'.repeat(-decimalPosition) + digits;
        }

        if (decimalPosition >= digits.length) {
            return sign + digits + '0'.repeat(decimalPosition - digits.length);
        }

        return sign + digits.slice(0, decimalPosition) + '.' + digits.slice(decimalPosition);
    }

    function parseDecimal(value) {
        var decimal = decimalString(value);

        if (decimal === '') {
            return { empty: true, valid: true, value: '' };
        }

        if (!decimalPattern.test(decimal)) {
            return { empty: false, valid: false, value: decimal };
        }

        var negative = decimal.charAt(0) === '-';
        var unsigned = (negative ? decimal.slice(1) : decimal).replace(/,/g, '');
        var parts = unsigned.split('.');
        var integer = (parts[0] || '0').replace(/^0+(?=\d)/, '');
        var fraction = parts.length > 1 ? parts[1].replace(/0+$/, '') : '';
        var isZero = /^0+$/.test(integer) && fraction === '';
        var normalized = integer + (fraction === '' ? '' : '.' + fraction);

        return {
            empty: false,
            valid: true,
            value: negative && !isZero ? '-' + normalized : normalized
        };
    }

    function normalize(value) {
        var parsed = parseDecimal(value);

        return parsed.valid ? parsed.value : null;
    }

    function format(value) {
        var parsed = parseDecimal(value);

        if (!parsed.valid) {
            return parsed.value;
        }

        if (parsed.empty) {
            return '';
        }

        var negative = parsed.value.charAt(0) === '-';
        var unsigned = negative ? parsed.value.slice(1) : parsed.value;
        var parts = unsigned.split('.');
        var grouped = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        var result = grouped + (parts.length > 1 ? '.' + parts[1] : '');

        return negative ? '-' + result : result;
    }

    function decimalPlaces(value) {
        var normalized = normalize(value);

        if (normalized === null || normalized.indexOf('.') === -1) {
            return 0;
        }

        return normalized.split('.')[1].length;
    }

    function compareMagnitudes(left, right) {
        var leftParts = left.split('.');
        var rightParts = right.split('.');
        var leftInteger = leftParts[0];
        var rightInteger = rightParts[0];

        if (leftInteger.length !== rightInteger.length) {
            return leftInteger.length > rightInteger.length ? 1 : -1;
        }

        if (leftInteger !== rightInteger) {
            return leftInteger > rightInteger ? 1 : -1;
        }

        var scale = Math.max((leftParts[1] || '').length, (rightParts[1] || '').length);
        var leftFraction = (leftParts[1] || '').padEnd(scale, '0');
        var rightFraction = (rightParts[1] || '').padEnd(scale, '0');

        if (leftFraction === rightFraction) {
            return 0;
        }

        return leftFraction > rightFraction ? 1 : -1;
    }

    function compare(left, right) {
        var normalizedLeft = normalize(left);
        var normalizedRight = normalize(right);

        if (normalizedLeft === null || normalizedRight === null || normalizedLeft === '' || normalizedRight === '') {
            return null;
        }

        var leftNegative = normalizedLeft.charAt(0) === '-';
        var rightNegative = normalizedRight.charAt(0) === '-';

        if (leftNegative !== rightNegative) {
            return leftNegative ? -1 : 1;
        }

        var magnitude = compareMagnitudes(
            leftNegative ? normalizedLeft.slice(1) : normalizedLeft,
            rightNegative ? normalizedRight.slice(1) : normalizedRight
        );

        return leftNegative ? -magnitude : magnitude;
    }

    function scaledInteger(value, scale) {
        var normalized = normalize(value);
        var negative = normalized.charAt(0) === '-';
        var unsigned = negative ? normalized.slice(1) : normalized;
        var parts = unsigned.split('.');
        var digits = (parts[0] + (parts[1] || '').padEnd(scale, '0')).replace(/^0+(?=\d)/, '');

        return BigInt((negative ? '-' : '') + digits);
    }

    function stepAligned(value, step, base) {
        if (step === null || step === undefined || step === '' || step === 'any') {
            return true;
        }

        var parsedValue = parseDecimal(value);
        var parsedStep = parseDecimal(step);
        var parsedBase = parseDecimal(base === null || base === undefined || base === '' ? '0' : base);

        if (
            typeof BigInt !== 'function'
            || !parsedValue.valid
            || parsedValue.empty
            || !parsedStep.valid
            || parsedStep.empty
            || compare(parsedStep.value, '0') !== 1
            || !parsedBase.valid
            || parsedBase.empty
        ) {
            return true;
        }

        var scale = Math.max(
            decimalPlaces(parsedValue.value),
            decimalPlaces(parsedStep.value),
            decimalPlaces(parsedBase.value)
        );
        var stepUnits = scaledInteger(parsedStep.value, scale);
        var delta = scaledInteger(parsedValue.value, scale) - scaledInteger(parsedBase.value, scale);

        return delta % stepUnits === BigInt(0);
    }

    function same(left, right) {
        var leftParsed = parseDecimal(left);
        var rightParsed = parseDecimal(right);

        return leftParsed.valid
            && rightParsed.valid
            && leftParsed.empty === rightParsed.empty
            && leftParsed.value === rightParsed.value;
    }

    function number(value, fallback) {
        var normalized = normalize(value);

        if (normalized === null || normalized === '') {
            return fallback === undefined ? 0 : fallback;
        }

        var numeric = Number(normalized);

        return Number.isFinite(numeric) ? numeric : (fallback === undefined ? 0 : fallback);
    }

    function inputScale(input) {
        var explicitScale = input.getAttribute('data-numeric-scale');

        if (explicitScale !== null && /^\d+$/.test(explicitScale)) {
            return Number(explicitScale);
        }

        var step = String(input.getAttribute('step') || '');
        var fraction = step.indexOf('.') === -1 ? '' : step.split('.')[1];

        return fraction.replace(/0+$/, '').length;
    }

    function validationMessage(key, fallback) {
        var messages = window.AppNumericInputMessages || {};

        return messages[key] || fallback;
    }

    function validateInput(input) {
        var parsed = parseDecimal(input.value);
        var message = '';
        var minimum = input.getAttribute('data-numeric-min');
        var maximum = input.getAttribute('data-numeric-max');
        var step = input.getAttribute('step');
        var stepBase = minimum !== null ? minimum : (input.getAttribute('value') || '0');

        if (!parsed.valid) {
            message = validationMessage('invalid', 'Enter a valid number.');
        } else if (!parsed.empty && decimalPlaces(parsed.value) > inputScale(input)) {
            message = validationMessage('precision', 'The number has too many decimal places.');
        } else if (!parsed.empty && !stepAligned(parsed.value, step, stepBase)) {
            message = validationMessage('step', 'Enter a value matching the allowed increment.');
        } else if (!parsed.empty) {
            var allowNegative = input.getAttribute('data-numeric-allow-negative');

            if (allowNegative === 'false' && parsed.value.charAt(0) === '-') {
                message = validationMessage('negative', 'Negative values are not allowed.');
            } else if (minimum !== null && compare(parsed.value, minimum) < 0) {
                message = validationMessage('minimum', 'The value is below the allowed minimum.');
            } else if (maximum !== null && compare(parsed.value, maximum) > 0) {
                message = validationMessage('maximum', 'The value exceeds the allowed maximum.');
            }
        }

        input.setCustomValidity(message);

        return message === '';
    }

    function normalizeInput(input) {
        var parsed = parseDecimal(input.value);

        if (parsed.valid) {
            input.value = parsed.value;
        }

        validateInput(input);
    }

    function formatInput(input) {
        var parsed = parseDecimal(input.value);

        if (parsed.valid) {
            input.value = format(parsed.value);
        }

        validateInput(input);
    }

    function inputs(root) {
        var scope = root || document;
        var result = [];

        if (scope.matches && scope.matches(selector)) {
            result.push(scope);
        }

        if (scope.querySelectorAll) {
            result = result.concat(Array.prototype.slice.call(scope.querySelectorAll(selector)));
        }

        return result;
    }

    function refresh(root) {
        inputs(root).forEach(function (input) {
            if (document.activeElement === input) {
                normalizeInput(input);
            } else {
                formatInput(input);
            }
        });
    }

    function normalizeForm(form) {
        inputs(form).forEach(normalizeInput);
    }

    function restoreFormDisplay(form) {
        window.setTimeout(function () {
            inputs(form).forEach(function (input) {
                if (document.activeElement !== input) {
                    formatInput(input);
                }
            });
        }, 0);
    }

    function initialize() {
        refresh(document);

        document.addEventListener('focusin', function (event) {
            if (event.target.matches && event.target.matches(selector)) {
                normalizeInput(event.target);
            }
        });

        document.addEventListener('focusout', function (event) {
            if (event.target.matches && event.target.matches(selector)) {
                formatInput(event.target);
            }
        });

        document.addEventListener('input', function (event) {
            if (event.target.matches && event.target.matches(selector)) {
                validateInput(event.target);
            }
        });

        document.addEventListener('submit', function (event) {
            if (event.target instanceof HTMLFormElement) {
                normalizeForm(event.target);
                restoreFormDisplay(event.target);
            }
        }, true);

        if (document.body && window.MutationObserver) {
            new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    Array.prototype.forEach.call(mutation.addedNodes || [], function (node) {
                        if (node.nodeType === 1) {
                            refresh(node);
                        }
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    window.AppNumbers = {
        compare: compare,
        decimalPlaces: decimalPlaces,
        format: format,
        formatInput: formatInput,
        normalize: normalize,
        normalizeForm: normalizeForm,
        number: number,
        parse: parseDecimal,
        refresh: refresh,
        same: same,
        stepAligned: stepAligned,
        validateInput: validateInput
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})(window, document);
