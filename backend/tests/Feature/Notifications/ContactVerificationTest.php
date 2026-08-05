<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Actions\ConfirmContactVerification;
use App\Modules\Notifications\Actions\RequestContactVerification;
use App\Modules\Notifications\Data\IssuedVerification;
use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Support\NotificationChannel;
use Laravel\Sanctum\Sanctum;

/**
 * Built and tested, with no consumer at launch: the in-app channel needs no
 * verified contact detail (research R12). It exists so the first external
 * channel is one class, not a class plus a verification flow plus a limiter.
 */
function requestFor(User $user, string $value = '+97455512345'): IssuedVerification
{
    return app(RequestContactVerification::class)->handle($user, NotificationChannel::WhatsApp, $value);
}

it('issues a code that is never stored in the clear', function (): void {
    $issued = requestFor(User::factory()->create());

    expect($issued->code)->toHaveLength(6)
        ->and($issued->verification->code_hash)->not->toBe($issued->code)
        // Even an accidental toArray() on a debug route must not expose it.
        ->and($issued->verification->toArray())->not->toHaveKey('code_hash');
});

it('confirms a correct code', function (): void {
    $issued = requestFor(User::factory()->create());

    app(ConfirmContactVerification::class)->handle($issued->verification, $issued->code);

    expect($issued->verification->fresh()->verified_at)->not->toBeNull();
});

it('refuses a wrong code and counts the attempt', function (): void {
    $verification = requestFor(User::factory()->create())->verification;

    try {
        app(ConfirmContactVerification::class)->handle($verification, '000000');
    } catch (DomainException) {
        // expected
    }

    expect($verification->fresh()->attempts)->toBe(1)
        ->and($verification->fresh()->verified_at)->toBeNull();
});

it('locks out after the attempt ceiling', function (): void {
    $issued = requestFor(User::factory()->create());

    for ($i = 0; $i < 5; $i++) {
        try {
            app(ConfirmContactVerification::class)->handle($issued->verification, '000000');
        } catch (DomainException) {
            // expected
        }
    }

    // The sixth try is refused before the comparison — even with the right code.
    expect(fn () => app(ConfirmContactVerification::class)
        ->handle($issued->verification->fresh(), $issued->code))
        ->toThrow(DomainException::class);
});

it('refuses an expired code', function (): void {
    $issued = requestFor(User::factory()->create());

    $this->travel(11)->minutes();

    expect(fn () => app(ConfirmContactVerification::class)
        ->handle($issued->verification->fresh(), $issued->code))
        ->toThrow(DomainException::class);
});

// FR-042 — changing a number must not leave the old one verified and reachable.
it('invalidates the previous verification when the contact changes', function (): void {
    $user = User::factory()->create();

    $first = requestFor($user, '+97455511111');
    app(ConfirmContactVerification::class)->handle($first->verification, $first->code);

    requestFor($user, '+97455522222');

    expect(ContactVerification::query()->where('user_id', $user->getKey())->count())->toBe(1)
        ->and(ContactVerification::query()->sole()->contact_value)->toBe('+97455522222')
        ->and(ContactVerification::query()->sole()->verified_at)->toBeNull();
});

it('never returns the code over HTTP', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson('/api/v1/contact-verifications', [
        'channel' => NotificationChannel::WhatsApp->value,
        'contact_value' => '+97455512345',
    ])->assertStatus(201);

    // A channel that echoes its own code back over HTTP has verified nothing.
    expect($response->json())->not->toHaveKey('code')
        ->and($response->json())->toHaveKeys(['uuid', 'expires_at']);
});

it('refuses to confirm someone else verification', function (): void {
    $issued = requestFor(User::factory()->create());

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/contact-verifications/{$issued->verification->uuid}/confirm", [
        'code' => $issued->code,
    ])->assertStatus(404);
});
