<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Jobs\EndIdleAuthSessionsJob;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\SessionEndReason;
use App\Modules\Tenancy\Support\PlatformSettings;
use Laravel\Sanctum\PersonalAccessToken;

/*
| The request-time guard (`IdleSessionExpiryTest`) only sees a token that is
| presented. This file is the other half: the token nobody presents again, whose
| session stayed `active` — holding a device slot — for ever.
|
| ⚠️ Real sign-ins, so `last_used_at` is written by Sanctum itself and the sweep
| is measured against the same column the guard reads.
*/
function sweepSignIn(string $email): array
{
    if (! User::query()->where('email', $email)->exists()) {
        User::factory()->create(['email' => $email, 'password' => 'password']);
    }

    return test()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'password',
    ])->assertOk()->json();
}

function sweepSession(string $uuid): AuthSession
{
    return AuthSession::query()->where('uuid', $uuid)->firstOrFail();
}

it('ends the session nobody used past the limit and keeps the one in use', function (): void {
    ['session_uuid' => $idleUuid] = sweepSignIn('idle@example.com');
    ['token' => $busyToken, 'session_uuid' => $busyUuid] = sweepSignIn('busy@example.com');

    $this->travel(10)->days();
    app('auth')->forgetGuards();
    $this->withToken($busyToken)->getJson('/api/v1/auth/me')->assertOk();

    // 31 days since the idle one was minted, 21 since the busy one was used.
    $this->travel(21)->days();

    dispatch_sync(new EndIdleAuthSessionsJob);

    $idle = sweepSession($idleUuid);
    expect($idle->status)->toBe(AuthSession::STATUS_ENDED)
        ->and($idle->ended_reason)->toBe(SessionEndReason::Idle)
        ->and(PersonalAccessToken::query()->whereKey($idle->token_id)->exists())->toBeFalse();

    $busy = sweepSession($busyUuid);
    expect($busy->status)->toBe(AuthSession::STATUS_ACTIVE)
        ->and(PersonalAccessToken::query()->whereKey($busy->token_id)->exists())->toBeTrue();

    // The sign-in screen can still say why.
    $this->getJson('/api/v1/auth/sessions/'.$idleUuid.'/end-reason')
        ->assertOk()
        ->assertJsonPath('reason', 'idle');
});

it('leaves a session inside the limit alone', function (): void {
    ['session_uuid' => $uuid] = sweepSignIn('recent@example.com');

    $this->travel(29)->days();

    dispatch_sync(new EndIdleAuthSessionsJob);

    expect(sweepSession($uuid)->status)->toBe(AuthSession::STATUS_ACTIVE);
});

it('touches nothing when the operator switched the rule off', function (): void {
    PlatformSettings::set('auth.session_idle_days', 0);

    ['session_uuid' => $uuid] = sweepSignIn('off@example.com');

    $this->travel(400)->days();

    dispatch_sync(new EndIdleAuthSessionsJob);

    $session = sweepSession($uuid);
    expect($session->status)->toBe(AuthSession::STATUS_ACTIVE)
        ->and(PersonalAccessToken::query()->whereKey($session->token_id)->exists())->toBeTrue();
});

it('deletes an idle token that has no active session behind it', function (): void {
    $user = User::factory()->create();
    $orphan = $user->createToken('legacy')->accessToken;
    $fresh = $user->createToken('legacy-fresh')->accessToken;

    $this->travel(31)->days();
    // Used yesterday: idle by creation date, not by use.
    $fresh->forceFill(['last_used_at' => now()->subDay()])->save();

    dispatch_sync(new EndIdleAuthSessionsJob);

    expect(PersonalAccessToken::query()->whereKey($orphan->getKey())->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->whereKey($fresh->getKey())->exists())->toBeTrue();
});

/*
| ⛔ A panel sign-in used to stay `active` for ever: its web session expires by
| itself after `session.lifetime` minutes of silence, and nothing ended the row.
| The panel arm judges `last_active_at` — kept moving by `TouchPanelSession` —
| against that lifetime, not against the thirty-day token rule.
*/
function sweepPanelRow(string $email): AuthSession
{
    ['session_uuid' => $uuid] = sweepSignIn($email);

    // Re-shape the row as a panel sign-in: no token, a web session id instead.
    $session = sweepSession($uuid);
    PersonalAccessToken::query()->whereKey($session->token_id)->delete();
    $session->forceFill(['token_id' => null, 'session_id' => 'panel-'.$uuid])->save();

    return $session;
}

it('ends a panel session that went quiet for longer than the web session lives', function (): void {
    $session = sweepPanelRow('panel@example.com');

    $this->travel((int) config('session.lifetime') + 11)->minutes();

    dispatch_sync(new EndIdleAuthSessionsJob);

    $session->refresh();
    expect($session->status)->toBe(AuthSession::STATUS_ENDED)
        ->and($session->ended_reason)->toBe(SessionEndReason::Idle);
});

it('keeps a panel session that was used inside the lifetime', function (): void {
    $session = sweepPanelRow('panel-busy@example.com');

    $this->travel((int) config('session.lifetime') + 60)->minutes();
    // What `TouchPanelSession` writes while the operator is working.
    $session->forceFill(['last_active_at' => now()->subMinutes(5)])->save();

    dispatch_sync(new EndIdleAuthSessionsJob);

    expect($session->fresh()?->status)->toBe(AuthSession::STATUS_ACTIVE);
});

it('still ends a quiet panel session when the token rule is switched off', function (): void {
    PlatformSettings::set('auth.session_idle_days', 0);

    $session = sweepPanelRow('panel-off@example.com');

    $this->travel(1)->day();

    dispatch_sync(new EndIdleAuthSessionsJob);

    expect($session->fresh()?->status)->toBe(AuthSession::STATUS_ENDED);
});

it('leaves a token session alone on the panel clock', function (): void {
    ['session_uuid' => $uuid] = sweepSignIn('token-day@example.com');

    // A day of silence: past the web session lifetime, far inside thirty days.
    $this->travel(1)->day();

    dispatch_sync(new EndIdleAuthSessionsJob);

    expect(sweepSession($uuid)->status)->toBe(AuthSession::STATUS_ACTIVE);
});
