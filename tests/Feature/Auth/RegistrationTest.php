<?php

use Illuminate\Support\Facades\Route;

test('registration routes are not available in the auth foundation', function () {
    expect(Route::has('register'))->toBeFalse();

    $this->get('/register')->assertNotFound();

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
});
