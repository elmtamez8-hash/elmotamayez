<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\SessionEndReason;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Tenancy\Support\Roles;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

/** The code the authenticator app would be showing right now. */
function currentCode(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp((string) $user->fresh()->getAppAuthenticationSecret());
}

/**
 * Enrol through the API, exactly as the settings screen does.
 *
 * @return array<int, string> the recovery codes, shown once
 */
function enrolTwoFactor(User $user): array
{
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/auth/2fa/setup', ['current_password' => 'password'])->assertOk();

    return test()->postJson('/api/v1/auth/2fa/confirm', ['code' => currentCode($user)])
        ->assertOk()
        ->json('recovery_codes');
}

function teacherAccount(): User
{
    return User::factory()->create([
        'email' => 'noura@example.com',
        'password' => 'password',
        'platform_role' => PlatformRole::Teacher,
    ]);
}

// SC-007. The password stops being enough, which is the entire point.
it('issues a challenge instead of a token when two-factor is on', function (): void {
    $user = teacherAccount();
    enrolTwoFactor($user);

    app('auth')->forgetGuards();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'noura@example.com',
        'password' => 'password',
    ])->assertOk();

    $response->assertJsonPath('two_factor', true)
        ->assertJsonStructure(['challenge']);

    // Not merely absent from the shape — absent from the body. A token here
    // would mean the second factor is decoration.
    expect($response->json('token'))->toBeNull();
});

it('exchanges a challenge and a code for a token', function (): void {
    $user = teacherAccount();
    enrolTwoFactor($user);

    app('auth')->forgetGuards();

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'noura@example.com',
        'password' => 'password',
    ])->json('challenge');

    $response = $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge' => $challenge,
        'code' => currentCode($user),
    ])->assertOk();

    expect($response->json('token'))->not->toBeNull();

    // Through StartAuthSession like any other sign-in, so the device limit and
    // its alert apply here too.
    expect(AuthSession::query()->where('uuid', $response->json('session_uuid'))->exists())->toBeTrue();

    // The challenge is spent, so a replay of the same pair buys nothing.
    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge' => $challenge,
        'code' => currentCode($user),
    ])->assertStatus(422);
});

it('refuses a wrong code without spending the challenge', function (): void {
    $user = teacherAccount();
    enrolTwoFactor($user);

    app('auth')->forgetGuards();

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'noura@example.com',
        'password' => 'password',
    ])->json('challenge');

    // Mistyping six digits is ordinary; being sent back to the password form
    // for it is not.
    $this->postJson('/api/v1/auth/2fa/challenge', ['challenge' => $challenge, 'code' => '000000'])
        ->assertStatus(422);

    $this->postJson('/api/v1/auth/2fa/challenge', ['challenge' => $challenge, 'code' => currentCode($user)])
        ->assertOk();
});

// FR-029.
it('spends a recovery code once, and says so', function (): void {
    $user = teacherAccount();
    $codes = enrolTwoFactor($user);

    expect($codes)->toHaveCount(8);

    app('auth')->forgetGuards();

    $signIn = fn (): array => $this->postJson('/api/v1/auth/login', [
        'email' => 'noura@example.com',
        'password' => 'password',
    ])->json();

    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge' => $signIn()['challenge'],
        'recovery_code' => $codes[0],
    ])->assertOk();

    // Same sheet, same code, second time: refused.
    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge' => $signIn()['challenge'],
        'recovery_code' => $codes[0],
    ])->assertStatus(422);

    // A second one still works — one code was spent, not the set.
    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge' => $signIn()['challenge'],
        'recovery_code' => $codes[1],
    ])->assertOk();

    // Either the account holder lost their phone, or someone else is holding
    // their printed sheet. Both are worth a message.
    expect(Notification::query()->where('recipient_user_id', $user->getKey())->count())
        ->toBeGreaterThanOrEqual(1);
});

it('never returns the secret or the codes when asked for the state', function (): void {
    $user = teacherAccount();
    $codes = enrolTwoFactor($user);

    $body = $this->getJson('/api/v1/auth/2fa')->assertOk();

    $body->assertJsonPath('enabled', true)
        ->assertJsonPath('recovery_codes_remaining', 8);

    $serialised = json_encode($body->json()) ?: '';

    expect($serialised)->not->toContain((string) $user->fresh()->getAppAuthenticationSecret())
        ->and($serialised)->not->toContain($codes[0]);
});

// FR-031.
it('ends every other session when the second factor changes', function (): void {
    $user = teacherAccount();

    // A session on another machine, opened before the change.
    $other = AuthSession::factory()->create([
        'user_id' => $user->getKey(),
        'device_id' => Device::factory()->create(['user_id' => $user->getKey()])->getKey(),
        'status' => AuthSession::STATUS_ACTIVE,
    ]);

    enrolTwoFactor($user);

    expect($other->fresh()->status)->toBe(AuthSession::STATUS_ENDED)
        ->and($other->fresh()->ended_reason)->toBe(SessionEndReason::TwoFactorChange);
});

it('requires the current password to enrol and a live code to leave', function (): void {
    $user = teacherAccount();
    enrolTwoFactor($user);

    // A borrowed session must not be able to swap the factor for its own.
    $this->deleteJson('/api/v1/auth/2fa', ['current_password' => 'wrong', 'code' => currentCode($user)])
        ->assertStatus(422);

    $this->deleteJson('/api/v1/auth/2fa', ['current_password' => 'password', 'code' => '000000'])
        ->assertStatus(422);

    $this->deleteJson('/api/v1/auth/2fa', ['current_password' => 'password', 'code' => currentCode($user)])
        ->assertNoContent();

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

// FR-028. Before the deadline it nags; after it, it refuses.
it('blocks a sensitive operation once the grace period has run out', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();

    Sanctum::actingAs($owner);
    $this->setCurrentWorkspace($workspace, $owner);

    // Creating the workspace started the clock, and it has not run out yet.
    expect($owner->securitySettings?->two_factor_required_at)->not->toBeNull();

    $this->patchJson("/api/v1/workspaces/{$workspace->uuid}", ['name' => 'Still Allowed'])
        ->assertOk();

    $owner->securitySettings()->update(['two_factor_required_at' => now()->subDay()]);
    $owner->unsetRelation('securitySettings');

    $this->patchJson("/api/v1/workspaces/{$workspace->uuid}", ['name' => 'Too Late'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'two_factor_required');
});

it('lets an enrolled account through the same sensitive operation', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner(ownerAttrs: ['password' => 'password']);

    enrolTwoFactor($owner);
    $this->setCurrentWorkspace($workspace, $owner->fresh());

    $owner->securitySettings()->update(['two_factor_required_at' => now()->subDay()]);

    $this->patchJson("/api/v1/workspaces/{$workspace->uuid}", ['name' => 'Allowed'])
        ->assertOk();
});

it('leaves students alone', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    // A mandate on an account that can only watch its own lessons trains people
    // to treat the requirement as noise.
    expect($student->securitySettings?->two_factor_required_at)->toBeNull();
});

// research §R9 — one enrolment, both surfaces.
it('gives the admin panel the same secret the API enrolled', function (): void {
    $user = teacherAccount();
    enrolTwoFactor($user);

    $provider = app(AppAuthentication::class);
    $user = $user->fresh();

    // Filament reads its own contract methods on User, which point at the same
    // row the API wrote. Two implementations would mean two secrets for one
    // person, and a QR code each.
    expect($provider->isEnabled($user))->toBeTrue()
        ->and($provider->getSecret($user))->toBe($user->getAppAuthenticationSecret());
});

/*
| The defect that only appeared for the person typing the key in by hand.
|
| Google Authenticator refuses a manually entered key shorter than 128 bits with
| "the key value is too short", and the provider's own generator returns 80 bits
| (16 base32 characters). Scanning the QR worked, so every automated path was
| green while enrolment was impossible for anyone whose camera or phone would not
| do it for them. RFC 4226 §4 recommends 160 bits, which is 32 characters.
*/
it('mints a secret long enough for an authenticator app to accept by hand', function (): void {
    $user = teacherAccount();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/2fa/setup', ['current_password' => 'password'])->assertOk();

    $secret = $user->refresh()->getAppAuthenticationSecret();

    expect($secret)->not->toBeNull()
        ->and(strlen((string) $secret))->toBe(32)
        // Base32, and nothing else: a character outside the alphabet is a key an
        // app rejects for a different reason with the same result.
        ->and((string) $secret)->toMatch('/^[A-Z2-7]+$/');
});
