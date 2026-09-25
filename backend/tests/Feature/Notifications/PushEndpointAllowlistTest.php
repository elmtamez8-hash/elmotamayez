<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Models\PushSubscription;
use Laravel\Sanctum\Sanctum;

/*
| The endpoint a browser registers is an address OUR server later POSTs to, on
| every notification. «Starts with https://» was the only check, so a signed-in
| account could make the server send requests to any host it named — an
| internal service, a cloud metadata address, somebody else's API. The host
| must be a push service a browser actually hands out endpoints on.
*/

function pushSubscriptionBody(string $endpoint): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => str_repeat('B', 87), 'auth' => str_repeat('a', 22)],
        'user_agent' => 'Mozilla/5.0 (Linux; Android 14)',
    ];
}

beforeEach(function (): void {
    configureWebPush();

    Sanctum::actingAs(User::factory()->create());
    $this->asGuest();
});

it('refuses an endpoint on a host that is not a push service', function (string $endpoint): void {
    $this->postJson('/api/v1/notifications/push-subscriptions', pushSubscriptionBody($endpoint))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('endpoint');

    expect(PushSubscription::query()->count())->toBe(0);
})->with([
    'an internal address' => 'https://169.254.169.254/latest/meta-data',
    'an arbitrary host' => 'https://attacker.test/collect',
    'a push host as a prefix' => 'https://fcm.googleapis.com.attacker.test/fcm/send/x',
    'a push host as userinfo' => 'https://fcm.googleapis.com@attacker.test/fcm/send/x',
    'a suffix without its dot' => 'https://xnotify.windows.com/w/?token=x',
    'a push host on another port' => 'https://fcm.googleapis.com:8443/fcm/send/x',
]);

it('accepts the endpoints real browsers hand out', function (string $endpoint): void {
    $this->postJson('/api/v1/notifications/push-subscriptions', pushSubscriptionBody($endpoint))
        ->assertNoContent();

    expect(PushSubscription::query()->where('endpoint', $endpoint)->exists())->toBeTrue();
})->with([
    'Chrome (FCM)' => 'https://fcm.googleapis.com/fcm/send/abc:APA91b',
    'Firefox' => 'https://updates.push.services.mozilla.com/wpush/v2/gAAAAA',
    'Edge (WNS)' => 'https://wns2-par02p.notify.windows.com/w/?token=BQYAAA',
    'Safari' => 'https://web.push.apple.com/QGuQyavXutnMH',
]);
