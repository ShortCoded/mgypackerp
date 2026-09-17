<?php

use App\Models\User;
use Modules\Core\Services\PwaSettingsService;
use Modules\Core\Services\SettingService;

test('service worker implements foreground push deduplication and safe notification clicks', function () {
    app(SettingService::class)->set(PwaSettingsService::EnabledKey, '1');
    app(SettingService::class)->set(PwaSettingsService::ServiceWorkerEnabledKey, '1');

    $response = $this->get(route('pwa.service-worker'))
        ->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/');
    $script = str_replace('\/', '/', $response->getContent());

    expect($script)
        ->toContain("self.addEventListener('push'")
        ->toContain("self.addEventListener('notificationclick'")
        ->toContain("type: 'ERP_PUSH_NOTIFICATION'")
        ->toContain("client.visibilityState === 'visible'")
        ->toContain('self.registration.showNotification')
        ->toContain('tag: payload.tag')
        ->toContain('silent: false')
        ->toContain('event.notification.close()')
        ->toContain("self.clients.matchAll({ type: 'window', includeUncontrolled: true })")
        ->toContain('existingClient.navigate(targetUrl)')
        ->toContain('existingClient.focus()')
        ->toContain('self.clients.openWindow(targetUrl)')
        ->toContain('target.origin === self.location.origin')
        ->toContain('/admin/notifications/');
});

test('pwa manifest has installable default icons when custom icons are not configured', function () {
    $manifest = $this->get(route('pwa.manifest'))
        ->assertOk()
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('start_url', route('dashboard', [], false))
        ->json();

    expect(collect($manifest['icons'])->pluck('sizes')->all())
        ->toContain('192x192', '512x512');

    expect(public_path('assets/img/favicon/web-app-manifest-192x192.png'))->toBeFile()
        ->and(public_path('assets/img/favicon/web-app-manifest-512x512.png'))->toBeFile();
});

test('authenticated layout exposes only public push configuration and explicit action', function () {
    config()->set('webpush.vapid.subject', 'mailto:qa@example.test');
    config()->set('webpush.vapid.public_key', 'visible-public-vapid-key');
    config()->set('webpush.vapid.private_key', 'never-visible-private-vapid-key');
    app(SettingService::class)->set(PwaSettingsService::EnabledKey, '1');
    app(SettingService::class)->set(PwaSettingsService::ServiceWorkerEnabledKey, '1');
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-push-notification-toggle', false)
        ->assertSee('assets/js/modules/Core/push-notifications.js', false)
        ->assertSee('vendors/sweetalert2/sweetalert2.all.min.js', false)
        ->assertSee('visible-public-vapid-key', false)
        ->assertDontSee('never-visible-private-vapid-key', false);

    $html = str_replace('\/', '/', $response->getContent());

    expect($html)
        ->toContain('window.AppPushNotifications')
        ->toContain(route('admin.notifications.push-subscriptions.store', [], false))
        ->toContain(route('admin.notifications.push-subscriptions.destroy', [], false));
});

test('stale push destinations continue to enforce current server authorization', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.settings.pwa'))
        ->assertForbidden();
});

test('notification permission is requested only from the explicit toggle workflow', function () {
    $script = file_get_contents(public_path('assets/js/modules/Core/push-notifications.js'));

    expect($script)
        ->toContain("toggleButton.addEventListener('click'")
        ->toContain('window.Notification.requestPermission()')
        ->toContain('async function enable()')
        ->not->toContain("window.addEventListener('load', enable");
});
