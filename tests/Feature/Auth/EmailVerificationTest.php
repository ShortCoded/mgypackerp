<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('email verification screen is not exposed because email is optional', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/verify-email');

    $response->assertNotFound();
});

test('email verification route is not registered', function () {
    $user = User::factory()->unverified()->create();

    expect(Route::has('verification.verify'))->toBeFalse()
        ->and($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('an arbitrary verification-shaped request cannot verify an email', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/verify-email/'.$user->getKey().'/'.sha1('wrong-email'))
        ->assertNotFound();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});
