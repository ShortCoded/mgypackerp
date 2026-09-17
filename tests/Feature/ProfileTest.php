<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\AuthLog;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\UserPresenceSession;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function profileActor(array $permissions): User
{
    static $sequence = 0;

    $sequence++;
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create([
        'name' => "Profile User {$sequence}",
        'username' => "profileuser{$sequence}",
        'email' => "profile{$sequence}@example.com",
        'phone' => '010000000'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
        'locale' => 'en',
        'notes' => 'Original profile notes',
    ]);

    $user->givePermissionTo($permissions);

    return $user;
}

test('authenticated user can view their own compact Falcon profile without internal ids', function () {
    $user = profileActor(['profile.view']);
    $role = Role::query()->create([
        'name' => 'Profile Role',
        'guard_name' => 'web',
        'doc_number' => 501,
        'doc_num' => 'Role-00501',
    ]);
    $user->assignRole($role);

    $response = $this->actingAs($user)
        ->get(route('profile.show'));

    $response
        ->assertOk()
        ->assertSee(__('profile.view_title'))
        ->assertDontSee('assets/img/generic/4.jpg', false)
        ->assertDontSee($user->doc_num)
        ->assertSee($user->username)
        ->assertSee(__('profile.sections.profile_information'))
        ->assertSee(__('profile.sections.account_summary'))
        ->assertDontSee(__('profile.sections.roles'))
        ->assertSee('Profile Role')
        ->assertDontSee('Role-00501')
        ->assertDontSee(__('profile.sections.password_security'))
        ->assertDontSee(__('profile.sections.active_sessions'))
        ->assertDontSee(__('profile.sections.login_activity'))
        ->assertDontSee(__('profile.permissions.delete'))
        ->assertDontSee(__('common.messages.not_available'))
        ->assertDontSee('data-id=', false);

    expect(substr_count($response->getContent(), 'Profile Role'))->toBe(1);
});

test('profile page and edit form do not expose a language or locale field', function () {
    $user = profileActor(['profile.view', 'profile.edit']);

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertDontSee(__('common.fields.locale'))
        ->assertDontSee('profile-locale', false)
        ->assertDontSee('name="locale"', false)
        ->assertDontSee('data-error-for="locale"', false)
        ->assertDontSee('fa-language', false);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee(__('common.fields.locale'))
        ->assertDontSee('profile-locale', false)
        ->assertDontSee('name="locale"', false)
        ->assertDontSee('data-error-for="locale"', false)
        ->assertDontSee('fa-language', false);
});

test('profile view permission is required to open profile page', function () {
    $user = profileActor([]);

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertForbidden();
});

test('profile link is shown in user dropdown only with view permission', function () {
    $user = profileActor(['dashboard.view', 'profile.view']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('profile.show'), false)
        ->assertSee(__('profile.actions.my_profile'));

    $userWithoutPermission = profileActor(['dashboard.view']);

    $this->actingAs($userWithoutPermission)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('profile.show'), false);
});

test('profile edit form appears only with profile edit permission', function () {
    $user = profileActor(['profile.view']);

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertDontSee(route('profile.edit'), false)
        ->assertDontSee('id="profile-name"', false);

    Permission::findOrCreate('profile.edit', 'web');
    $user->givePermissionTo('profile.edit');

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertSee(route('profile.edit'), false)
        ->assertDontSee('id="profile-name"', false);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('id="profile-name"', false)
        ->assertSee(route('profile.update'), false);
});

test('profile edit route requires edit permission and updates via ajax', function () {
    $user = profileActor(['profile.view']);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertForbidden();

    $this->actingAs($user)
        ->putJson(route('profile.update'), [
            'name' => 'Forbidden Profile User',
            'username' => 'forbiddenprofileuser',
            'email' => 'forbidden-profile@example.com',
            'phone' => '01000000111',
            'notes' => 'Forbidden notes',
        ])
        ->assertForbidden();

    Permission::findOrCreate('profile.edit', 'web');
    $user->givePermissionTo('profile.edit');

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('assets/js/modules/Auth/profile.js', false)
        ->assertSee('data-shortcut-action="form.save"', false)
        ->assertDontSee(__('profile.sections.roles'))
        ->assertDontSee('name="locale"', false)
        ->assertDontSee('name="roles[]"', false);

    $response = $this->actingAs($user)
        ->putJson(route('profile.update'), [
            'name' => 'Updated Profile User',
            'username' => 'updatedprofileuser',
            'email' => 'updated-profile@example.com',
            'phone' => '01000000011',
            'locale' => 'ar',
            'notes' => 'Updated notes',
            'submit_action' => 'save',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('profile.messages.updated_successfully'));

    expect($response->json('data'))->not->toHaveKey('locale');

    $user->refresh();

    expect($user->name)->toBe('Updated Profile User')
        ->and($user->username)->toBe('updatedprofileuser')
        ->and($user->email)->toBe('updated-profile@example.com')
        ->and($user->phone)->toBe('01000000011')
        ->and($user->locale)->toBe('en')
        ->and($user->notes)->toBe('Updated notes')
        ->and($user->updated_by)->toBe($user->id);

    $activity = Activity::query()->where('action', 'profile.update')->firstOrFail();

    expect($activity->properties->get('user_doc_num'))->toBe($user->doc_num)
        ->and($activity->properties->get('username'))->toBe('updatedprofileuser')
        ->and($activity->properties->get('changed_fields'))->toBe(['name', 'username', 'email', 'phone', 'notes'])
        ->and($activity->properties->has('id'))->toBeFalse()
        ->and($activity->properties->has('user_id'))->toBeFalse()
        ->and($activity->properties->has('password'))->toBeFalse()
        ->and($activity->properties->has('remember_token'))->toBeFalse();
});

test('profile edit request does not require locale and keeps locale persistence untouched', function () {
    $user = profileActor(['profile.edit']);
    $user->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($user)
        ->putJson(route('profile.update'), [
            'name' => 'Locale Free Profile User',
            'username' => 'localefreeprofileuser',
            'email' => 'locale-free-profile@example.com',
            'phone' => '01000000999',
            'notes' => 'Locale was not posted',
            'submit_action' => 'save',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($user->refresh()->locale)->toBe('ar');
});

test('profile password form and route require password update permission', function () {
    $user = profileActor(['profile.view']);

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertDontSee(__('profile.sections.password_security'))
        ->assertDontSee(route('password.update'), false);

    $this->put(route('password.update'), [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])
        ->assertForbidden();

    Permission::findOrCreate('profile.password.update', 'web');
    $user->givePermissionTo('profile.password.update');

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertSee(__('profile.sections.password_security'))
        ->assertSee(route('password.update'), false)
        ->assertSee(__('profile.actions.update_password'));
});

test('current password is required for profile password changes', function () {
    $user = profileActor(['profile.password.update']);
    $originalPassword = $user->password;

    $this->actingAs($user)
        ->from(route('profile.show'))
        ->put(route('password.update'), [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect(route('profile.show'))
        ->assertSessionHasErrors(['current_password'], null, 'updatePassword');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue()
        ->and($user->password)->toBe($originalPassword);
});

test('profile password validation styles appear only after submitting the password form', function () {
    $user = profileActor(['profile.view', 'profile.password.update']);

    $initialResponse = $this->actingAs($user)
        ->get(route('profile.show'));

    $initialResponse
        ->assertOk()
        ->assertSee('class="js-profile-password-form"', false)
        ->assertSee('method="POST" novalidate', false);

    preg_match('/<input[^>]*id="profile-current-password"[^>]*>/', $initialResponse->getContent(), $initialCurrentPassword);
    preg_match('/<input[^>]*id="profile-new-password"[^>]*>/', $initialResponse->getContent(), $initialNewPassword);
    preg_match('/<input[^>]*id="profile-password-confirmation"[^>]*>/', $initialResponse->getContent(), $initialPasswordConfirmation);

    expect($initialCurrentPassword[0] ?? '')->not->toContain('is-invalid')
        ->and($initialNewPassword[0] ?? '')->not->toContain('is-invalid')
        ->and($initialPasswordConfirmation[0] ?? '')->not->toContain('is-invalid');

    $this->from(route('profile.show'))
        ->put(route('password.update'), [])
        ->assertRedirect(route('profile.show'))
        ->assertSessionHasErrors(['current_password', 'password'], null, 'updatePassword');

    $submittedResponse = $this->get(route('profile.show'));

    preg_match('/<input[^>]*id="profile-current-password"[^>]*>/', $submittedResponse->getContent(), $submittedCurrentPassword);
    preg_match('/<input[^>]*id="profile-new-password"[^>]*>/', $submittedResponse->getContent(), $submittedNewPassword);

    expect($submittedCurrentPassword[0] ?? '')->toContain('is-invalid')
        ->and($submittedNewPassword[0] ?? '')->toContain('is-invalid');
});

test('empty optional profile values render blank without unavailable placeholders', function () {
    $user = profileActor(['profile.view']);
    $user->forceFill([
        'email' => null,
        'phone' => null,
        'notes' => null,
        'last_login_at' => null,
        'last_login_ip' => null,
    ])->save();

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertDontSee(__('common.messages.not_available'))
        ->assertDontSee('غير متاح');
});

test('profile sessions and login activity are scoped to the current user and hide raw technical data', function () {
    $user = profileActor(['profile.view', 'profile.sessions.view', 'profile.auth_logs.view']);
    $otherUser = profileActor([]);

    UserPresenceSession::query()->create([
        'user_id' => $user->id,
        'session_fingerprint' => 'current-user-session-fingerprint',
        'status' => 'online',
        'ip_address' => '198.51.100.10',
        'browser_name' => 'Firefox',
        'os_name' => 'Linux',
        'device_type' => 'desktop',
        'login_at' => now()->subHour(),
        'last_seen_at' => now()->subMinutes(10),
        'context' => ['raw_session_secret' => 'session-json-secret'],
    ]);

    UserPresenceSession::query()->create([
        'user_id' => $otherUser->id,
        'session_fingerprint' => 'other-user-session-fingerprint',
        'status' => 'online',
        'ip_address' => '203.0.113.77',
        'browser_name' => 'Safari',
        'os_name' => 'macOS',
        'device_type' => 'desktop',
        'login_at' => now()->subHour(),
        'last_seen_at' => now()->subMinutes(10),
        'context' => ['raw_session_secret' => 'other-session-json-secret'],
    ]);

    AuthLog::query()->create([
        'user_id' => $user->id,
        'event' => 'login_success',
        'status' => 'success',
        'identifier' => $user->email,
        'ip_address' => '198.51.100.11',
        'client_ip' => '198.51.100.12',
        'browser_name' => 'Chrome',
        'os_name' => 'Windows',
        'device_type' => 'desktop',
        'city' => 'Cairo',
        'country' => 'Egypt',
        'latitude' => 30.0444,
        'longitude' => 31.2357,
        'context' => ['raw_auth_secret' => 'auth-json-secret'],
    ]);

    AuthLog::query()->create([
        'user_id' => $otherUser->id,
        'event' => 'login_success',
        'status' => 'success',
        'identifier' => $otherUser->email,
        'ip_address' => '203.0.113.88',
        'browser_name' => 'Edge',
        'os_name' => 'Windows',
        'device_type' => 'desktop',
        'context' => ['raw_auth_secret' => 'other-auth-json-secret'],
    ]);

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertSee(__('profile.sections.active_sessions'))
        ->assertSee(__('profile.sections.login_activity'))
        ->assertSee('Firefox on Linux')
        ->assertSee('198.51.100.10')
        ->assertSee(__('auth_logs.events.login_success'))
        ->assertSee('Chrome on Windows')
        ->assertSee('198.51.100.11')
        ->assertSee(__('auth_logs.actions.view_on_map'))
        ->assertDontSee('current-user-session-fingerprint')
        ->assertDontSee('session_fingerprint')
        ->assertDontSee('raw_session_secret')
        ->assertDontSee('session-json-secret')
        ->assertDontSee('raw_auth_secret')
        ->assertDontSee('auth-json-secret')
        ->assertDontSee('203.0.113.77')
        ->assertDontSee('203.0.113.88')
        ->assertDontSee('other-user-session-fingerprint')
        ->assertDontSee('other-session-json-secret')
        ->assertDontSee('other-auth-json-secret');
});

test('profile delete account section is not exposed because self deletion is disabled', function () {
    $user = profileActor(['profile.view', 'profile.delete']);

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertDontSee(__('profile.permissions.delete'))
        ->assertDontSee('profile-delete-account', false);
});

test('profile update ignores unsafe fields and does not log unchanged attempts', function () {
    $user = profileActor(['profile.edit']);
    $originalPassword = $user->password;

    $this->actingAs($user)
        ->putJson(route('profile.update'), [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'notes' => $user->notes,
            'status' => 'blocked',
            'password' => 'new-password',
        ])
        ->assertOk()
        ->assertJsonPath('type', 'no_changes')
        ->assertJsonPath('message', __('common.messages.no_changes'));

    $user->refresh();

    expect($user->status)->toBe('active')
        ->and($user->password)->toBe($originalPassword)
        ->and(Activity::query()->where('action', 'profile.update')->exists())->toBeFalse();
});

test('profile update rejects hacked role assignment payloads', function () {
    $user = profileActor(['profile.edit']);
    $role = Role::query()->create([
        'name' => 'Hacked Profile Role',
        'guard_name' => 'web',
        'doc_number' => 502,
        'doc_num' => 'Role-00502',
    ]);

    $this->actingAs($user)
        ->putJson(route('profile.update'), [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'notes' => $user->notes,
            'roles' => [$role->doc_num],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['roles']);

    expect($user->refresh()->roles)->toHaveCount(0);
});

test('profile validation is soft delete aware for public login fields', function () {
    $user = profileActor(['profile.edit']);
    User::factory()->create([
        'username' => 'takenprofileuser',
        'email' => 'taken-profile@example.com',
        'phone' => '01000000012',
    ]);

    $this->actingAs($user)
        ->putJson(route('profile.update'), [
            'name' => 'Profile User',
            'username' => 'takenprofileuser',
            'email' => 'taken-profile@example.com',
            'phone' => '01000000012',
            'notes' => 'Original profile notes',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['username', 'email', 'phone']);
});
