<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Channels\WebPushChannel;
use App\Modules\Notifications\Models\PushSubscription;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;

/*
| Spec 012 · US2 · T078 — the channel's three answers.
|
| ⚠️ NONE OF THIS IS REACHABLE THROUGH `Http::fake()`. minishlink drives its own
| auto-discovered PSR-18 client, so the HTTP facade never sees a push and
| `preventStrayRequests()` never catches one; the container binding is the whole
| seam, which is why `fakeWebPush()` exists.
*/

it('is disabled with no VAPID keys, so a delivery is skipped rather than failed', function (): void {
    configureWebPush(false);

    expect(app(WebPushChannel::class)->isEnabled())->toBeFalse();
});

it('refuses to call itself enabled with the signing key alone', function (): void {
    configureWebPush(false);
    config()->set('webpush.vapid.public', 'BTestPublicKey');
    config()->set('webpush.vapid.private', 'test-private-key');

    /*
    | ⚠️ `subject` IS THE `sub` CLAIM OF THE SIGNED JWT, NOT DECORATION. A push
    | service is entitled to refuse a token without one, so «configured» has to
    | mean the same thing here as it does at the far end — otherwise every message
    | is dispatched, attempted, and fails at the provider with a reason nobody can
    | map back to a missing env value.
    */
    expect(app(WebPushChannel::class)->isEnabled())->toBeFalse();
});

it('cannot reach an account with no device registered', function (): void {
    configureWebPush();

    $user = User::factory()->create();

    // FR-035 with no line of its own: refusing the browser prompt disables
    // nothing, it simply leaves nothing to push to.
    expect(app(WebPushChannel::class)->canReach(envelopeFor($user)))->toBeFalse();
});

it('reaches an account the moment one device is registered', function (): void {
    configureWebPush();

    $user = User::factory()->create();
    PushSubscription::factory()->create(['user_id' => $user->getKey()]);

    expect(app(WebPushChannel::class)->canReach(envelopeFor($user)))->toBeTrue();
});

it('sends a title and a link, and never the message body', function (): void {
    configureWebPush();
    $fake = fakeWebPush();

    $user = User::factory()->create();
    PushSubscription::factory()->create(['user_id' => $user->getKey()]);

    app(WebPushChannel::class)->send(envelopeFor($user));

    $payload = json_decode((string) $fake->sent[0]['payload'], true, 512, JSON_THROW_ON_ERROR);

    /*
    | ⚠️ THE ASSERTION IS AN ABSENCE, AND IT IS THE POINT OF THE WHOLE PAYLOAD
    | DESIGN. The protocol has no revocation but `410`, and a phone is shared
    | between a guardian and their child — a `security_alert` or an absence report
    | rendered on a lock screen is read by whoever is standing beside it.
    */
    expect($payload)->toHaveKeys(['title', 'url', 'uuid'])
        ->and($payload)->not->toHaveKey('body')
        ->and(array_values($payload))->not->toContain('نصّ');
});

it('deletes the row when the push service says the subscription is gone', function (): void {
    configureWebPush();

    $user = User::factory()->create();
    $dead = PushSubscription::factory()->create(['user_id' => $user->getKey()]);

    fakeWebPush([$dead->endpoint => 410]);

    /*
    | Permanent, and it takes the row with it. `410 Gone` is the ONLY revocation
    | the push protocol has — leave the row and every later notification is
    | attempted against a device that no longer exists, for ever.
    */
    expect(fn () => app(WebPushChannel::class)->send(envelopeFor($user)))
        ->toThrow(RuntimeException::class);

    expect(PushSubscription::query()->where('user_id', $user->getKey())->count())->toBe(0);
});

it('treats 404 as gone as well, because the library does', function (): void {
    configureWebPush();

    $user = User::factory()->create();
    $dead = PushSubscription::factory()->create(['user_id' => $user->getKey()]);

    fakeWebPush([$dead->endpoint => 404]);

    /*
    | ⚠️ THE TASK TEXT SAYS «410», AND A LITERAL `=== 410` WOULD BE WRONG.
    | `MessageSentReport::isSubscriptionExpired()` covers 404 and 410 both, and
    | Mozilla answers 404 for an endpoint it has forgotten. A hand-copied status
    | list would leave those rows in the table until the retention sweep took them
    | two years later.
    */
    expect(fn () => app(WebPushChannel::class)->send(envelopeFor($user)))
        ->toThrow(RuntimeException::class);

    expect(PushSubscription::query()->where('user_id', $user->getKey())->count())->toBe(0);
});

it('keeps the other devices when one of them is gone', function (): void {
    configureWebPush();

    $user = User::factory()->create();
    $dead = PushSubscription::factory()->create(['user_id' => $user->getKey()]);
    $live = PushSubscription::factory()->create(['user_id' => $user->getKey()]);

    $fake = fakeWebPush([$dead->endpoint => 410]);

    // One dead phone is not a failed notification: the laptop got it.
    app(WebPushChannel::class)->send(envelopeFor($user));

    expect($fake->countTo($live->endpoint))->toBe(1)
        ->and(PushSubscription::query()->where('user_id', $user->getKey())->pluck('id')->all())
        ->toBe([$live->getKey()]);
});

it('stays out of every type default', function (): void {
    /*
    | Repeated here as well as in `PushDefaultsUnchangedTest` on purpose: this file
    | is where somebody reads the channel, and «why does nothing arrive» is
    | answered by that fact and not by anything in the class.
    */
    foreach (NotificationType::cases() as $type) {
        expect($type->defaultChannels())->not->toContain(NotificationChannel::Push);
    }
});
