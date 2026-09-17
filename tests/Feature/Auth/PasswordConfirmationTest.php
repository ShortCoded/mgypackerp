<?php

use App\Models\User;

test('standalone password confirmation screen is not exposed', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/confirm-password');

    $response->assertNotFound();
});

test('standalone password confirmation post is not exposed for a valid password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/confirm-password', [
        'password' => 'password',
    ]);

    $response->assertNotFound();
});

test('standalone password confirmation post is not exposed for an invalid password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/confirm-password', [
        'password' => 'wrong-password',
    ]);

    $response->assertNotFound();
});
