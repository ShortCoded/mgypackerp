<?php

use App\Models\User;

test('authenticated layouts expose an opaque user scoped notification coordination identity', function (): void {
    $configurationFor = function (User $user): array {
        $html = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $notificationsMatched = preg_match('/window\.AppNotifications = (\{.*?\});/s', $html, $notificationMatches);
        $sessionMatched = preg_match('/window\.AppSession = (\{.*?\});/s', $html, $sessionMatches);

        expect($notificationsMatched)->toBe(1)
            ->and($sessionMatched)->toBe(1);

        return [
            'notifications' => json_decode($notificationMatches[1], true, flags: JSON_THROW_ON_ERROR),
            'session' => json_decode($sessionMatches[1], true, flags: JSON_THROW_ON_ERROR),
        ];
    };

    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $firstConfiguration = $configurationFor($firstUser);
    $secondConfiguration = $configurationFor($secondUser);
    $firstIdentity = $firstConfiguration['notifications']['coordinationIdentity'];
    $secondIdentity = $secondConfiguration['notifications']['coordinationIdentity'];

    expect($firstIdentity)
        ->toBe($firstConfiguration['session']['identity'])
        ->toHaveLength(64)
        ->not->toBe((string) $firstUser->getKey())
        ->not->toBe((string) $firstUser->getRouteKey())
        ->and($secondIdentity)
        ->toBe($secondConfiguration['session']['identity'])
        ->not->toBe($firstIdentity);
});
