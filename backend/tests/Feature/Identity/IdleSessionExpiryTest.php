<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\SessionEndReason;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

/*
| ⛔ A BEARER TOKEN USED TO LIVE FOR EVER.
|
| `sanctum.expiration` is null and `AuthSession::active()` asks the status alone,
| so a token copied off a shared computer in January still opened the account in
| December. The owner's decision: a session ends after 30 days WITHOUT USE — idle,
| not absolute, so somebody who opens the product every week is never signed out.
| ⚠️ AMENDED 2026-09-27 (owner-approved audit fix): an absolute 30-day lifetime
| now sits beside it, so that person signs in again once a month.
|
| ⚠️ Every request here carries a REAL token. `Sanctum::actingAs()` never runs the
| guard, so the check under test would be invisible to it and every case would
| pass against a build with no check at all.
*/
function idleSignIn(): array
{
    User::factory()->create([
        'email' => 'rania@example.com',
        'password' => 'password',
    ]);

    return test()->postJson('/api/v1/auth/login', [
        'email' => 'rania@example.com',
        'password' => 'password',
    ])->assertOk()->json();
}

function idleCall(string $token): TestResponse
{
    // The guard caches the user it resolved; a second request in one test must
    // not be answered from that cache.
    app('auth')->forgetGuards();

    return test()->withToken($token)->getJson('/api/v1/auth/me');
}

it('keeps a session alive for as long as it is used', function (): void {
    // The IDLE rule alone: the absolute ceiling (2026-09-27, below) is switched
    // off here, because this case is about «used» versus «unused».
    config(['sanctum.expiration' => null]);

    ['token' => $token] = idleSignIn();

    // 58 days since the token was minted, never 30 in a row without use — an
    // ABSOLUTE expiry would already have ended it.
    $this->travel(29)->days();
    idleCall($token)->assertOk();

    $this->travel(29)->days();
    idleCall($token)->assertOk();
});

it('ends a session left unused past the limit, and says why', function (): void {
    ['token' => $token, 'session_uuid' => $uuid] = idleSignIn();

    $this->travel(31)->days();

    idleCall($token)->assertUnauthorized();

    $session = AuthSession::query()->where('uuid', $uuid)->firstOrFail();

    expect($session->status)->toBe(AuthSession::STATUS_ENDED)
        ->and($session->ended_reason)->toBe(SessionEndReason::Idle)
        ->and(PersonalAccessToken::query()->whereKey($session->token_id)->exists())->toBeFalse();

    // The sign-in screen asks this with no token, and must be able to explain.
    $this->getJson('/api/v1/auth/sessions/'.$uuid.'/end-reason')
        ->assertOk()
        ->assertJsonPath('reason', 'idle');
});

/*
| ⚠️ THE ABSOLUTE CEILING, SINCE 2026-09-27 (`sanctum.expiration`, 30 days).
| Idle expiry never ends a stolen token that is USED — so a token in daily use
| now dies too, and its session row says `expired` rather than staying «active»
| over a token that no longer works. The end-reason endpoint is the real check:
| a bare 401 is what Sanctum gives with or without the row being closed.
*/
it('ends a session in daily use once it passes the absolute lifetime, and says why', function (): void {
    ['token' => $token, 'session_uuid' => $uuid] = idleSignIn();

    foreach (range(1, 29) as $ignored) {
        $this->travel(1)->days();
        idleCall($token)->assertOk();
    }

    $this->travel(1)->days();
    $this->travel(1)->minutes();

    idleCall($token)->assertUnauthorized();

    $session = AuthSession::query()->where('uuid', $uuid)->firstOrFail();

    expect($session->status)->toBe(AuthSession::STATUS_ENDED)
        ->and($session->ended_reason)->toBe(SessionEndReason::Expired);

    $this->getJson('/api/v1/auth/sessions/'.$uuid.'/end-reason')
        ->assertOk()
        ->assertJsonPath('reason', 'expired');
});

it('reads the limit from the platform settings', function (): void {
    PlatformSettings::set('auth.session_idle_days', 7);

    ['token' => $token] = idleSignIn();

    $this->travel(8)->days();

    idleCall($token)->assertUnauthorized();
});

it('records when a session was last used', function (): void {
    ['token' => $token, 'session_uuid' => $uuid] = idleSignIn();

    $this->travel(10)->days();
    idleCall($token)->assertOk();

    $session = AuthSession::query()->where('uuid', $uuid)->firstOrFail();

    // «Last active» on the devices screen, and the device-limit alert's «was it
    // in use a moment ago», both read this column — it used to hold the sign-in
    // time for ever.
    expect($session->last_active_at?->isToday())->toBeTrue();
});
