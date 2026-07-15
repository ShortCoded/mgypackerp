<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Modules\Auth\Models\MailConfiguration;
use Modules\Auth\Notifications\QueuedResetPasswordNotification;

function createPasswordResetMailConfiguration(): MailConfiguration
{
    return MailConfiguration::create([
        'name' => 'Testing SMTP',
        'mailer' => 'smtp',
        'host' => '127.0.0.1',
        'port' => 2525,
        'from_address' => 'noreply@example.com',
        'from_name' => 'ERP',
        'is_active' => true,
    ]);
}

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response
        ->assertStatus(200)
        ->assertSee('assets/js/modules/Core/falcon-defaults.js', false)
        ->assertSee('window.ErpFalconDefaults.apply();', false);
});

test('reset password link can be requested', function () {
    Notification::fake();
    createPasswordResetMailConfiguration();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedResetPasswordNotification::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();
    createPasswordResetMailConfiguration();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedResetPasswordNotification::class, function ($notification) {
        $response = $this->get('/reset-password/'.$notification->token);

        $response
            ->assertStatus(200)
            ->assertSee('assets/js/modules/Core/falcon-defaults.js', false)
            ->assertSee('window.ErpFalconDefaults.apply();', false);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();
    createPasswordResetMailConfiguration();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedResetPasswordNotification::class, function ($notification) use ($user) {
        $response = $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});
