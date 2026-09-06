<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Modules\Inventory\Http\Requests\StoreInventoryOperationRequest;
use Modules\Production\Http\Requests\StoreProductionRunRequest;
use Modules\Sales\Http\Requests\StoreCustomerInvoiceRequest;
use Modules\Sales\Http\Requests\StoreCustomerReceiptRequest;
use Modules\Sales\Http\Requests\StoreSalesOrderRequest;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('screen translation references resolve in Arabic without fallback', function (): void {
    $missing = [];

    foreach ([resource_path('views'), base_path('modules'), app_path()] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('~\b__\(\s*(\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")\s*[,)]~s', $file->getContents(), $matches);

            foreach (array_unique($matches[1]) as $literal) {
                $key = substr($literal, 1, -1);
                if (str_contains($key, '$')) {
                    continue;
                }

                $key = $literal[0] === "'"
                    ? str_replace(["\\'", '\\\\'], ["'", '\\'], $key)
                    : stripcslashes($key);

                if (! Lang::hasForLocale($key, 'ar')) {
                    $missing[$key] = $file->getRelativePathname();
                }
            }
        }
    }

    expect($missing)->toBe([]);
});

test('Arabic and English catalogs have matching keys and preserve placeholders', function (): void {
    foreach (File::files(resource_path('lang/en')) as $file) {
        $english = Arr::dot(require $file->getPathname());
        $arabic = Arr::dot(require resource_path('lang/ar/'.$file->getFilename()));
        $englishKeys = array_keys($english);
        $arabicKeys = array_keys($arabic);
        sort($englishKeys);
        sort($arabicKeys);

        expect($arabicKeys)->toBe($englishKeys, $file->getFilename());
    }

    $translations = json_decode(File::get(resource_path('lang/ar.json')), true, 512, JSON_THROW_ON_ERROR);
    foreach ($translations as $key => $translation) {
        preg_match_all('/(?<![\w]):[a-zA-Z_][a-zA-Z_0-9]*/', $key, $source);
        preg_match_all('/(?<![\w]):[a-zA-Z_][a-zA-Z_0-9]*/', $translation, $target);
        sort($source[0]);
        sort($target[0]);

        expect($translation)->not->toBeEmpty()
            ->and($target[0])->toBe($source[0], $key);
    }
});

test('workflow status and type labels have Arabic translations', function (): void {
    $missing = [];

    foreach (['Sales', 'Inventory', 'Production', 'Purchases', 'Finance'] as $module) {
        foreach (File::files(base_path("modules/{$module}/Models")) as $file) {
            $class = 'Modules\\'.$module.'\\Models\\'.$file->getBasename('.php');
            foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
                if (! is_string($value) || ! preg_match('/^(.*Status|Type|Method|Reason|Source|Result|Credit)/', $name)) {
                    continue;
                }

                $label = str($value)->replace('_', ' ')->title()->toString();
                if (! Lang::hasForLocale($label, 'ar')) {
                    $missing[] = $class.'::'.$name.' = '.$label;
                }
            }
        }
    }

    expect($missing)->toBe([]);
});

test('sales browser messages follow the active locale', function (string $locale, string $saved, string $failed): void {
    app()->setLocale($locale);

    $html = view('modules.sales.cycle.partials.scripts')->render();
    preg_match('/window\.salesCycleMessages = (.*);/', $html, $matches);
    $messages = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

    expect($messages['saved'])->toBe($saved)
        ->and($messages['actionFailed'])->toBe($failed)
        ->and($messages['unexpectedError'])->toBe(__('Unexpected browser error.'))
        ->and($html)->toContain('sales-cycle.js?v=');
})->with([
    'Arabic' => ['ar', 'تم الحفظ بنجاح.', 'تعذّر إتمام الإجراء.'],
    'English' => ['en', 'Saved successfully.', 'The action could not be completed.'],
]);

test('sales browser submission uses localized fallbacks and preserves server validation', function (string $locale): void {
    app()->setLocale($locale);
    $html = view('modules.sales.cycle.partials.scripts')->render();
    preg_match('/window\.salesCycleMessages = (.*);/', $html, $matches);

    $script = <<<'JS'
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(process.argv[1], 'utf8');
const messages = JSON.parse(process.argv[2]);
for (const scenario of ['network', 'empty-error', 'success', 'validation']) {
    const events = {};
    const alert = { textContent: '', classList: { remove() {} }, scrollIntoView() {} };
    const submitter = { disabled: false };
    const notifications = [];
    let reloads = 0;
    const form = {
        dataset: {}, action: '/test', method: 'post',
        querySelector: selector => selector === '.js-sales-form-alert' ? alert : selector === '[type="submit"]' ? submitter : selector === '[name="_submission_token"]' ? form.token || null : null,
        append: element => { form.token = element; },
        querySelectorAll: () => [],
        addEventListener: (name, callback) => { events[name] = callback; },
    };
    const document = {
        createElement: () => ({}),
        querySelector: () => null,
        querySelectorAll: selector => selector === '.js-sales-cycle-form, .js-sales-cycle-action' ? [form] : [],
        addEventListener: (name, callback) => { events[name] = callback; },
    };
    const window = {
        salesCycleMessages: messages,
        AppAlerts: { toast: (type, message) => notifications.push([type, message]) },
        location: { reload: () => { reloads++; } },
    };
    runInNewContext(source, {
        window, document, crypto: { randomUUID: () => 'd8d08adb-a4a4-4f54-a94c-78db7f2c50a2' }, FormData: class {},
        fetch: async () => {
            if (scenario === 'network') throw new Error('Failed to fetch');
            return {
                ok: scenario === 'success',
                json: async () => scenario === 'validation' ? { errors: { quantity: ['رسالة التحقق من الخادم'] } } : {},
            };
        },
    });
    events.DOMContentLoaded();
    events.submit({ preventDefault() {} });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(submitter.disabled, false);
    if (scenario === 'success') {
        assert.deepEqual(notifications, [['success', messages.saved]]);
        assert.equal(reloads, 1);
    } else {
        assert.equal(alert.textContent, scenario === 'network' ? messages.unexpectedError : scenario === 'validation' ? 'رسالة التحقق من الخادم' : messages.actionFailed);
        assert.equal(reloads, 0);
    }
}
JS;

    $process = new Process([
        'node', '--input-type=module', '-e', $script,
        public_path('assets/js/modules/Sales/sales-cycle.js'), $matches[1],
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
})->with(['ar', 'en']);

test('operational forms have localized validation attributes including nested lines', function (string $requestClass, string $locale): void {
    app()->setLocale($locale);
    $request = new $requestClass;
    $attributes = __('validation.attributes');

    expect(array_values(array_diff(array_keys($request->rules()), array_keys($attributes))))->toBe([]);

    $validator = Validator::make(
        ['lines' => [['quantity' => 0]]],
        ['lines.*.quantity' => ['required', 'numeric', 'gt:0']],
    );

    expect($validator->errors()->first('lines.0.quantity'))
        ->toContain($locale === 'ar' ? 'الكمية' : 'Quantity')
        ->not->toContain('lines.0.quantity');
})->with([
    StoreSalesOrderRequest::class,
    StoreCustomerInvoiceRequest::class,
    StoreCustomerReceiptRequest::class,
    StoreInventoryOperationRequest::class,
    StoreProductionRunRequest::class,
])->with(['ar', 'en']);
