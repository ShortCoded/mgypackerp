<?php

use App\Models\User;
use Modules\Auth\Models\AuthLog;

function csrfRefreshHeaders(array $overrides = []): array
{
    return array_merge([
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
        'Sec-Fetch-Site' => 'same-origin',
    ], $overrides);
}

test('guest ajax requests can refresh the current csrf token', function () {
    $response = $this
        ->withHeaders(csrfRefreshHeaders())
        ->get(route('auth.csrf-token'));

    $response
        ->assertOk()
        ->assertJsonStructure([
            'ok',
            'csrf_token',
        ])
        ->assertJson([
            'ok' => true,
        ])
        ->assertHeader('Pragma', 'no-cache')
        ->assertHeader('Expires', 'Fri, 01 Jan 1990 00:00:00 GMT');

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('no-cache')
        ->toContain('must-revalidate')
        ->toContain('max-age=0')
        ->toContain('private')
        ->and($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();

    expect($response->json('csrf_token'))->toBe(csrf_token());
    expect(AuthLog::query()->exists())->toBeFalse();
});

test('csrf token endpoint requires ajax json headers', function () {
    $this
        ->get(route('auth.csrf-token'))
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/json');

    $this
        ->withHeaders(csrfRefreshHeaders(['Accept' => 'text/html']))
        ->get(route('auth.csrf-token'))
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/json');

    $this
        ->withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'fetch',
            'Sec-Fetch-Site' => 'same-origin',
        ])
        ->get(route('auth.csrf-token'))
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/json');
});

test('csrf token endpoint rejects cross site fetch requests', function () {
    $this
        ->withHeaders(csrfRefreshHeaders(['Sec-Fetch-Site' => 'cross-site']))
        ->get(route('auth.csrf-token'))
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/json');
});

test('csrf token endpoint is available to locked sessions for stale lock screen submits', function () {
    $this
        ->actingAs(User::factory()->create())
        ->withSession(['auth_locked' => true])
        ->withHeaders(csrfRefreshHeaders())
        ->get(route('auth.csrf-token'))
        ->assertOk()
        ->assertJson([
            'ok' => true,
        ]);
});
