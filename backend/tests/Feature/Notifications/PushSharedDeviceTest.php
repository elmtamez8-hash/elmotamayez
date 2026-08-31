<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\PushSubscription;
use App\Modules\Notifications\Support\DeliveryStatus;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · US2 · T079 — A GUARDIAN AND THEIR CHILD SHARE A PHONE.
|
| That is the family shape spec 013 built `parent_student_relations` around, and
| it is the reason `push_subscriptions` is unique on `(user_id, endpoint_hash)`
| rather than on the hash alone.
|
| ⚠️ WITH A GLOBAL UNIQUE THE SECOND SUBSCRIBER STEALS THE FIRST ONE'S ROW. The
| first account then stops receiving EVERYTHING — the mandatory `security_alert`
| included — with no error, no failed delivery and no line in any log. The mirror
| of it is an authenticated attacker holding somebody else's endpoint taking over
| their row.
|
| ⚠️ AND A TEST WITH ONE ACCOUNT PASSES STRAIGHT OVER BOTH. Two users on one
| endpoint is the entire fixture; anything less is measuring something else.
*/

const SHARED_ENDPOINT = 'https://fcm.googleapis.com/fcm/send/one-family-phone';

function subscribeAs(User $user, string $endpoint): void
{
    Sanctum::actingAs($user);
    test()->asGuest();

    test()->postJson('/api/v1/notifications/push-subscriptions', [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => str_repeat('B', 87), 'auth' => str_repeat('a', 22)],
        'user_agent' => 'Mozilla/5.0 (Linux; Android 14)',
    ])->assertNoContent();
}

it('keeps a row for each account on one shared device', function (): void {
    configureWebPush();

    $parent = User::factory()->create();
    $child = User::factory()->create();

    subscribeAs($parent, SHARED_ENDPOINT);
    subscribeAs($child, SHARED_ENDPOINT);

    expect(PushSubscription::query()->count())->toBe(2)
        ->and(PushSubscription::query()->where('user_id', $parent->getKey())->count())->toBe(1)
        ->and(PushSubscription::query()->where('user_id', $child->getKey())->count())->toBe(1);
});

it('delivers to both accounts on that one device', function (): void {
    configureWebPush();
    $fake = fakeWebPush();

    $parent = User::factory()->create();
    $child = User::factory()->create();

    subscribeAs($parent, SHARED_ENDPOINT);
    subscribeAs($child, SHARED_ENDPOINT);

    foreach ([$parent, $child] as $recipient) {
        app(DispatchNotification::class)->handle(new NotificationRequest(
            recipient: $recipient,
            type: NotificationType::SessionReport,
            variables: [
                'title' => 'حصّة الجبر',
                'student_name' => 'طالب',
                'status' => 'حاضر',
                'minutes' => '45',
                'note' => '—',
            ],
            subject: $recipient,
        ));
    }

    /*
    | ⚠️ TWO PUSHES, NOT ONE. Under a global unique this is 1 — the surviving row
    | belongs to whoever subscribed second, and the first account's message is
    | recorded SKIPPED with the reason «no verified way to reach this channel»,
    | which reads as the person never having subscribed.
    */
    expect($fake->countTo(SHARED_ENDPOINT))->toBe(2);

    $pushDeliveries = NotificationDelivery::query()
        ->where('channel', NotificationChannel::Push->value)
        ->get();

    expect($pushDeliveries)->toHaveCount(2)
        ->and($pushDeliveries->pluck('status')->unique()->all())
        ->toBe([DeliveryStatus::Delivered->value]);
});

it('registers the same device twice without a collision', function (): void {
    configureWebPush();

    $user = User::factory()->create();

    /*
    | ⚠️ THE HAPPY PATH OF THE FEATURE, AND `updateOrCreate` WOULD 500 ON IT. A
    | service worker re-registers on every visit and two tabs do it at once;
    | `firstOrNew` + `save` is a read then a write with a gap between them, so the
    | second one hits the unique index. `upsert()` is one statement that is both
    | the check and the write.
    */
    subscribeAs($user, SHARED_ENDPOINT);
    subscribeAs($user, SHARED_ENDPOINT);

    expect(PushSubscription::query()->where('user_id', $user->getKey())->count())->toBe(1);
});

it('answers 204 whether it created the row or updated it', function (): void {
    configureWebPush();

    $user = User::factory()->create();

    Sanctum::actingAs($user);
    $this->asGuest();

    $body = [
        'endpoint' => SHARED_ENDPOINT,
        'keys' => ['p256dh' => str_repeat('B', 87), 'auth' => str_repeat('a', 22)],
    ];

    /*
    | ⚠️ `201` AGAINST `200` IS AN ORACLE: it tells whoever asks whether that
    | endpoint is already registered to this account, which is the very thing the
    | compound key was chosen to make unanswerable. The same rule closes `DELETE`
    | from the other side — 204 for a row that was there and for one that was not.
    */
    $this->postJson('/api/v1/notifications/push-subscriptions', $body)->assertNoContent();
    $this->postJson('/api/v1/notifications/push-subscriptions', $body)->assertNoContent();

    $this->deleteJson('/api/v1/notifications/push-subscriptions', ['endpoint' => SHARED_ENDPOINT])
        ->assertNoContent();
    $this->deleteJson('/api/v1/notifications/push-subscriptions', ['endpoint' => SHARED_ENDPOINT])
        ->assertNoContent();

    expect(PushSubscription::query()->count())->toBe(0);
});

it('refuses one account the right to forget another account device', function (): void {
    configureWebPush();

    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    subscribeAs($owner, SHARED_ENDPOINT);

    Sanctum::actingAs($stranger);
    $this->asGuest();

    // 204, because a distinct answer would be the same oracle wearing a 404 —
    // and the row is untouched, which is what the constraint on `user_id` buys.
    $this->deleteJson('/api/v1/notifications/push-subscriptions', ['endpoint' => SHARED_ENDPOINT])
        ->assertNoContent();

    expect(PushSubscription::query()->where('user_id', $owner->getKey())->count())->toBe(1);
});

it('writes the push preference rows without them nothing is ever delivered', function (): void {
    configureWebPush();

    $user = User::factory()->create();
    subscribeAs($user, SHARED_ENDPOINT);

    /*
    | `Push` is deliberately absent from `defaultChannels()`, and
    | `PreferenceResolver` REPLACES the defaults with a stored row rather than
    | filtering them — so subscribing has to write those rows or the channel is
    | switched on for nobody at all.
    |
    | ⚠️ AND THE ROW CARRIES THE DEFAULTS TOO. A row naming push alone would show
    | the settings grid with in-app unticked for thirty types, while the feed keeps
    | receiving them — a screen lying about a mute that is not real.
    */
    $preference = NotificationPreference::query()
        ->where('user_id', $user->getKey())
        ->where('type', NotificationType::SessionReport->value)
        ->sole();

    expect($preference->channels)->toContain(NotificationChannel::Push->value)
        ->and($preference->channels)->toContain(NotificationChannel::InApp->value);
});

it('never resurrects push into a type the person muted', function (): void {
    configureWebPush();

    $user = User::factory()->create();

    Sanctum::actingAs($user);
    $this->asGuest();

    $this->putJson('/api/v1/notifications/preferences', [
        'preferences' => [[
            'type' => NotificationType::SessionReport->value,
            'channels' => [NotificationChannel::InApp->value],
        ]],
    ])->assertOk();

    subscribeAs($user, SHARED_ENDPOINT);

    /*
    | ⚠️ `insertOrIgnore`, AND CREATE-IF-ABSENT IS THE WHOLE POINT. A service
    | worker re-registers on every visit, so an upsert here would put push back
    | into a muted type every single time the person opened the app — a mute that
    | will not stay muted, with nothing to blame for it.
    */
    $preference = NotificationPreference::query()
        ->where('user_id', $user->getKey())
        ->where('type', NotificationType::SessionReport->value)
        ->sole();

    expect($preference->channels)->toBe([NotificationChannel::InApp->value]);
});
