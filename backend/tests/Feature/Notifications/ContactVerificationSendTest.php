<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationChannel;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
| SC-009 — the closed loop, opened.
|
| canReach() asks whether the recipient has a VERIFIED number, and the
| verification code is by definition the message that goes to an UNVERIFIED one.
| Sent through DispatchNotification it would be skipped every time, for ever, and
| no number on this platform could ever be proven — the channel would be built,
| green, and decorative.
|
| Before spec 020 the controller dropped the code on the floor with a comment
| saying it "reaches the user over the channel being verified". Nothing did that.
*/

beforeEach(function (): void {
    configureWhatsApp();
    approveWhatsAppTemplate('contact_verification');
});

it('sends the code to a number nobody has verified yet', function (): void {
    Http::fake(['provider.test/*' => Http::response([], 200)]);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/contact-verifications', [
        'channel' => NotificationChannel::WhatsApp->value,
        'contact_value' => '033123456',
    ])->assertCreated();

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['template']['name'] === 'contact_verification'
            // Normalised on the way IN, so the column never holds two shapes of
            // one number and the channel carries no clean-up logic for ever.
            && $body['to'] === '97433123456'
            && strlen($body['template']['components'][0]['parameters'][0]['text']) === 6;
    });
});

it('creates no notification row, because this message is not a notification', function (): void {
    Http::fake(['provider.test/*' => Http::response([], 200)]);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/contact-verifications', [
        'channel' => NotificationChannel::WhatsApp->value,
        'contact_value' => '+97433123456',
    ])->assertCreated();

    // A one-time code has no business in the feed a person reads later — and the
    // feed is exactly what DispatchNotification writes before consulting any
    // channel.
    expect(Notification::query()->count())->toBe(0);
});

it('never returns the code over HTTP', function (): void {
    Http::fake(['provider.test/*' => Http::response([], 200)]);

    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson('/api/v1/contact-verifications', [
        'channel' => NotificationChannel::WhatsApp->value,
        'contact_value' => '+97433123456',
    ])->assertCreated();

    // A channel that echoes the code back to the caller has verified nothing.
    expect($response->json())->not->toHaveKey('code');
});

it('refuses a number it cannot read, under its own field', function (): void {
    Http::preventStrayRequests();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/contact-verifications', [
        'channel' => NotificationChannel::WhatsApp->value,
        'contact_value' => 'رقمي القديم',
    ])->assertStatus(422)->assertJsonValidationErrors('contact_value');

    expect(ContactVerification::query()->count())->toBe(0);
});

it('tells the user in our own words when the provider refuses', function (): void {
    Http::fake(['provider.test/*' => Http::response(['error' => ['message' => 'Template not approved']], 400)]);

    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson('/api/v1/contact-verifications', [
        'channel' => NotificationChannel::WhatsApp->value,
        'contact_value' => '+97433123456',
    ])->assertStatus(422);

    // Surfaced, because the user is watching a screen waiting for a code that is
    // never coming — but as our sentence: a raw upstream message on a screen is
    // the rule this product does not break, and it would leak what we send
    // through.
    expect($response->json('errors.contact_value.0'))->not->toContain('Template');
});

it('changes nothing when the channel is not implemented', function (): void {
    Http::preventStrayRequests();

    Sanctum::actingAs(User::factory()->create());

    // Email is a known channel with no class. The pre-020 behaviour is preserved
    // exactly: a row is issued and nothing is sent.
    $this->postJson('/api/v1/contact-verifications', [
        'channel' => NotificationChannel::Email->value,
        'contact_value' => 'parent@example.test',
    ])->assertCreated();

    expect(ContactVerification::query()->count())->toBe(1);
});

it('does not send when the channel is switched off', function (): void {
    Http::preventStrayRequests();
    configureWhatsApp(enabled: false);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/contact-verifications', [
        'channel' => NotificationChannel::WhatsApp->value,
        'contact_value' => '+97433123456',
    ])->assertCreated();

    expect(MessageTemplate::query()->where('type', 'contact_verification')->exists())->toBeTrue();
});
