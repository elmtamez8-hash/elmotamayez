<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\SessionEndReason;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;

/*
| ⛔ TWO DOORS CHANGE A PASSWORD, AND ONLY ONE OF THEM REVOKED ANYTHING.
|
| `changePassword()` has signed the other devices out since FR-031, and says why:
| «if the password was changed because it leaked, leaving the intruder signed in
| defeats the change». `resetPassword()` — fifty lines below, and the door
| somebody uses AFTER a compromise, because they can no longer sign in to reach
| the first one — wrote the new hash, rotated `remember_token`, fired
| `PasswordReset`, and stopped.
|
| ⚠️ NEITHER OF THOSE IS A REVOCATION. `remember_token` governs Laravel's
| remember-me cookie and has no bearing on a Sanctum bearer token or on an
| `auth_sessions` row, and `PasswordReset` reaches no listener in this tree
| (measured across `app/`, `routes/`, `bootstrap/` and `config/`).
|
| So: an intruder phishes a reset link, signs in, and holds a token. The account
| holder notices, runs the forgot-password flow themselves — and the intruder's
| session is untouched, for ever, while the screen says the password was changed.
*/
it('signs every live session out when a password is RESET, not only when it is changed', function (): void {
    $user = User::factory()->create([
        'email' => 'nour@example.com',
        'password' => 'password',
    ]);

    // The intruder's device: a real sign-in, so a real token and a real row.
    $signIn = $this->withHeaders(['X-Device-Id' => 'stolen-laptop'])
        ->postJson('/api/v1/auth/login', ['email' => 'nour@example.com', 'password' => 'password'])
        ->assertOk()
        ->json();

    expect(PersonalAccessToken::query()->count())->toBe(1);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'nour@example.com',
        'token' => Password::createToken($user),
        'password' => 'brand-new-pass-9',
        'password_confirmation' => 'brand-new-pass-9',
    ])->assertOk();

    $session = AuthSession::query()->where('uuid', $signIn['session_uuid'])->firstOrFail();

    expect($session->status)->toBe(AuthSession::STATUS_ENDED)
        ->and($session->ended_reason)->toBe(SessionEndReason::PasswordChange)
        // The one number a client cannot forge: the token is gone, not merely
        // marked. A row flipped to `ended` beside a live token is a session that
        // still opens every screen.
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

/*
| ⚠️ AND THE RESETTER IS NOT SPARED, WHICH IS THE DIFFERENCE FROM THE SIBLING.
| `changePassword()` passes the current token to `$keepTokenId` so the person is
| not thrown out of the screen they just used. Somebody resetting holds no token
| at all, so there is nothing to keep — and every live session belongs to
| whoever had the OLD password, which is precisely the population being evicted.
*/
it('mints no session of its own for the person resetting', function (): void {
    $user = User::factory()->create(['email' => 'nour@example.com', 'password' => 'password']);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'nour@example.com',
        'token' => Password::createToken($user),
        'password' => 'brand-new-pass-9',
        'password_confirmation' => 'brand-new-pass-9',
    ])->assertOk();

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(AuthSession::query()->count())->toBe(0);
});
