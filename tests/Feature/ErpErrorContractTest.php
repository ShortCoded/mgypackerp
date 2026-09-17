<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Route::middleware('web')->prefix('_test/erp-error-contract')->group(function (): void {
        Route::get('/validation/{locale}', function (string $locale) {
            app()->setLocale($locale);

            throw ValidationException::withMessages([
                'name' => [__('erp_errors.duplicate_name')],
            ]);
        });

        Route::get('/unexpected/{locale}', function (string $locale) {
            app()->setLocale($locale);

            throw new RuntimeException('secret SQL: select * from payroll_tokens');
        });

        Route::get('/database', function () {
            $previous = new PDOException('duplicate key value contains secret');
            $previous->errorInfo = [
                '23505',
                0,
                'duplicate key violates unique constraint "unknown_sensitive_constraint"',
            ];

            throw new QueryException(
                'pgsql',
                'insert into sensitive_table (token) values (?)',
                ['secret-token'],
                $previous,
            );
        });

        Route::get('/database-known/{locale}', function (string $locale) {
            app()->setLocale($locale);
            $message = 'duplicate key violates unique constraint "accounts_company_account_code_unique_active"';
            $previous = new PDOException($message);
            $previous->errorInfo = ['23505', 0, $message];

            throw new QueryException('pgsql', 'insert into accounts values (?)', ['sensitive'], $previous);
        });

        Route::get('/forbidden/{locale}', function (string $locale) {
            app()->setLocale($locale);
            abort(403);
        });

        Route::get('/missing/{locale}', function (string $locale) {
            app()->setLocale($locale);
            abort(404);
        });

        Route::get('/expired/{locale}', function (string $locale) {
            app()->setLocale($locale);

            throw new TokenMismatchException;
        });

        Route::get('/unauthenticated/{locale}', function (string $locale) {
            app()->setLocale($locale);

            throw new AuthenticationException;
        });

        Route::get('/limited/{locale}', function (string $locale) {
            app()->setLocale($locale);
            abort(429);
        });

        Route::get('/validation-limited', function () {
            throw ValidationException::withMessages([
                'login' => ['Too many attempts.'],
            ])->status(429);
        });

        Route::get('/legacy-response/{locale}', function (string $locale) {
            app()->setLocale($locale);

            return response()->json([
                'success' => false,
                'message' => __('erp_errors.record_in_use'),
                'data' => ['blocked_records' => [['doc_num' => 'SAFE-1']]],
            ], 422);
        });

        Route::get('/locked', fn () => response()->json([
            'authenticated' => true,
            'locked' => true,
            'action' => 'lock',
            'lock_screen_url' => '/lock-screen',
        ], 423));

        Route::get('/legacy-500', fn () => response()->json([
            'success' => false,
            'message' => 'Server Error: select * from secrets',
            'data' => ['sql' => 'select * from secrets'],
        ], 500));

        Route::post('/upload', function (Request $request) {
            $request->validate(['document' => ['required', 'file', 'max:64']]);

            return response()->json(['success' => true, 'message' => 'uploaded']);
        });
    });
});

test('validation responses use the localized field envelope in both locales', function (string $locale, string $expected): void {
    $response = $this->getJson("/_test/erp-error-contract/validation/{$locale}");

    $response
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'validation_failed')
        ->assertJsonPath('errors.name.0', $expected)
        ->assertJsonMissingPath('correlation_id');
})->with([
    'English' => ['en', 'This name already exists.'],
    'Arabic' => ['ar', 'هذا الاسم موجود بالفعل.'],
]);

test('validation exception status is preserved by the shared json error contract', function (): void {
    $this->getJson('/_test/erp-error-contract/validation-limited')
        ->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'rate_limited')
        ->assertJsonValidationErrors(['login']);
});

test('unexpected JSON failures are safe localized and traceable', function (string $locale, string $expectedPrefix): void {
    $response = $this->getJson("/_test/erp-error-contract/unexpected/{$locale}");

    $response
        ->assertStatus(500)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'internal_error')
        ->assertJsonStructure(['message', 'correlation_id']);

    expect($response->json('message'))->toStartWith($expectedPrefix)
        ->and($response->json('correlation_id'))->toMatch('/^[0-9a-f-]{36}$/')
        ->and($response->getContent())->not->toContain('secret SQL')
        ->not->toContain('payroll_tokens')
        ->not->toContain('RuntimeException');
})->with([
    'English' => ['en', 'An unexpected error occurred. Reference:'],
    'Arabic' => ['ar', 'حدث خطأ غير متوقع. رقم المتابعة:'],
]);

test('unknown database constraints never expose SQL or driver messages', function (): void {
    $response = $this->getJson('/_test/erp-error-contract/database');

    $response
        ->assertStatus(500)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'internal_error')
        ->assertJsonStructure(['correlation_id']);

    expect($response->getContent())
        ->not->toContain('sensitive_table')
        ->not->toContain('secret-token')
        ->not->toContain('unknown_sensitive_constraint')
        ->not->toContain('duplicate key');
});

test('known database collisions use the localized field contract', function (string $locale, string $expected): void {
    $this->getJson("/_test/erp-error-contract/database-known/{$locale}")
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'duplicate_account_code')
        ->assertJsonPath('message', $expected)
        ->assertJsonPath('errors.account_code.0', $expected)
        ->assertJsonMissingPath('correlation_id');
})->with([
    'English' => ['en', 'This account code is already in use.'],
    'Arabic' => ['ar', 'كود الحساب مستخدم بالفعل.'],
]);

test('authorization missing resource and expired session statuses have stable codes', function (string $path, int $status, string $errorCode): void {
    $this->getJson("/_test/erp-error-contract/{$path}/en")
        ->assertStatus($status)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', $errorCode);
})->with([
    'permission' => ['forbidden', 403, 'permission_denied'],
    'missing record' => ['missing', 404, 'not_found'],
    'session expiry' => ['expired', 419, 'session_expired'],
    'authentication' => ['unauthenticated', 401, 'authentication_required'],
    'rate limit' => ['limited', 429, 'rate_limited'],
]);

test('legacy controller errors are normalized while safe extension data is preserved', function (): void {
    $this->getJson('/_test/erp-error-contract/legacy-response/en')
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'record_in_use')
        ->assertJsonPath('data.blocked_records.0.doc_num', 'SAFE-1');
});

test('the existing lock-screen response contract is preserved inside the envelope', function (): void {
    $this->getJson('/_test/erp-error-contract/locked')
        ->assertStatus(423)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'session_locked')
        ->assertJsonPath('locked', true)
        ->assertJsonPath('action', 'lock')
        ->assertJsonPath('lock_screen_url', '/lock-screen');
});

test('legacy 500 responses are sanitized and receive a correlation id', function (): void {
    $response = $this->getJson('/_test/erp-error-contract/legacy-500');

    $response
        ->assertStatus(500)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'internal_error')
        ->assertJsonStructure(['correlation_id'])
        ->assertJsonMissingPath('data');

    expect($response->getContent())
        ->not->toContain('Server Error')
        ->not->toContain('select * from secrets');
});

test('ordinary HTML requests retain the web exception response', function (): void {
    $response = $this->get('/_test/erp-error-contract/unexpected/en');

    $response->assertStatus(500);

    expect((string) $response->headers->get('content-type'))->toContain('text/html');
});

test('multipart file uploads continue through their normal request path', function (): void {
    $response = $this
        ->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->post('/_test/erp-error-contract/upload', [
            'document' => UploadedFile::fake()->create('sample.pdf', 4, 'application/pdf'),
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'uploaded');
});

test('shared ajax forms use one pending submission guard and restore it on completion', function (): void {
    $handler = file_get_contents(public_path('assets/js/modules/Core/ajax-errors.js'));
    $fixedAssets = file_get_contents(public_path('assets/js/modules/FixedAssets/fixed-assets.js'));
    $customers = file_get_contents(public_path('assets/js/modules/Sales/customers.js'));
    $suppliers = file_get_contents(public_path('assets/js/modules/Purchases/suppliers.js'));

    expect($handler)
        ->toContain('$form.data(\'appAjaxSubmitting\') === true')
        ->toContain('$form.data(\'appAjaxSubmitting\', true)')
        ->toContain('$form.data(\'appAjaxSubmitting\', false)')
        ->and($fixedAssets)->toContain('AppAjaxErrors.beginSubmission')->toContain('AppAjaxErrors.endSubmission')
        ->and($customers)->toContain('beginSubmission($form, $button)')->toContain('endSubmission($form, $button)')
        ->and($suppliers)->toContain('beginSubmission($form, $button)')->toContain('endSubmission($form, $button)');
});
