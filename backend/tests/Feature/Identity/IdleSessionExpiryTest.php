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
