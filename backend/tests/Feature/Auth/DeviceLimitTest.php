<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\SessionEndReason;
use App\Modules\Tenancy\Support\PlatformSettings;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

function student(): User
{
    return User::factory()->create([
        'email' => 'tala@example.com',
        'password' => 'password',
        'platform_role' => PlatformRole::Student,
    ]);
}

/** Sign in as if from a particular machine. */
function signInFrom(string $deviceId, string $agent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'): array
{
    return test()->withHeaders([
        'X-Device-Id' => $deviceId,
        'User-Agent' => $agent,
    ])->postJson('/api/v1/auth/login', [
        'email' => 'tala@example.com',
        'password' => 'password',
    ])->json();
}

// SC-006. One account, one machine at a time.
it('ends the first device when a second signs in', function (): void {
    $user = student();

    $first = signInFrom('laptop');
    $second = signInFrom('phone');

    $sessions = AuthSession::query()->where('user_id', $user->getKey())->get();

    expect($sessions)->toHaveCount(2);

    $firstSession = $sessions->firstWhere('uuid', $first['session_uuid']);
    $secondSession = $sessions->firstWhere('uuid', $second['session_uuid']);

    expect($firstSession->status)->toBe(AuthSession::STATUS_ENDED)
        ->and($firstSession->ended_reason)->toBe(SessionEndReason::DeviceLimit)
        // FR-023: the new sign-in is never the one refused. Whoever is holding
        // the phone is as likely to be the account holder as whoever left the
        // laptop open.
        ->and($secondSession->status)->toBe(AuthSession::STATUS_ACTIVE);
});

it('never refuses the new sign-in', function (): void {
    student();

    signInFrom('laptop');

    $second = test()->withHeaders(['X-Device-Id' => 'phone'])
        ->postJson('/api/v1/auth/login', [
            'email' => 'tala@example.com',
            'password' => 'password',
        ]);

    $second->assertOk()->assertJsonStructure(['token', 'session_uuid']);
});

// SC-006ج · FR-022ب. The correction that makes a limit of one survivable: the
// unit is the device, so signing in twice on one laptop is still one machine.
it('keeps two sessions on the same device', function (): void {
    $user = student();

    signInFrom('laptop');
    signInFrom('laptop');

    $active = AuthSession::query()
        ->where('user_id', $user->getKey())
        ->active()
        ->get();

    expect($active)->toHaveCount(2)
        ->and($active->pluck('device_id')->unique())->toHaveCount(1);
});

it('really deletes the evicted token, not just the row', function (): void {
    student();

    $first = signInFrom('laptop');
    signInFrom('phone');

    // Deleting the token IS the enforcement — Sanctum then refuses on its own,
    // with no middleware re-checking what it already checks.
    $this->withHeader('Authorization', 'Bearer '.$first['token'])
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();

    expect(PersonalAccessToken::query()->count())->toBe(1);
});

// SC-006ب. FR-022 asks for an adjustable limit; a config constant is adjustable
// only by shipping code.
it('honours a limit changed from the settings table without a deploy', function (): void {
    $user = student();

    PlatformSettings::set('auth.device_limits', ['student' => 2]);

    signInFrom('laptop');
    signInFrom('phone');

    expect(AuthSession::query()->where('user_id', $user->getKey())->active()->count())->toBe(2);

    signInFrom('tablet');

    expect(AuthSession::query()->where('user_id', $user->getKey())->active()->count())->toBe(2);
});

it('leaves a role with no configured limit alone', function (): void {
    // A teacher with the panel, a laptop and a phone open is doing legitimate
    // work; a limit there obstructs them rather than protecting anything.
    $teacher = User::factory()->create([
        'email' => 'tala@example.com',
        'password' => 'password',
        'platform_role' => PlatformRole::Teacher,
    ]);

    signInFrom('laptop');
    signInFrom('phone');
    signInFrom('tablet');

    expect(AuthSession::query()->where('user_id', $teacher->getKey())->active()->count())->toBe(3);
});

it('tells the signed-out device why, without saying anything else', function (): void {
    student();

    $first = signInFrom('laptop');
    signInFrom('phone');

    // Asked after the token is gone, so it cannot be authenticated — and it
    // answers with two fields and nothing that identifies anyone.
    $response = $this->getJson("/api/v1/auth/sessions/{$first['session_uuid']}/end-reason")
        ->assertOk()
        ->assertJsonPath('reason', 'device_limit');

    expect(array_keys($response->json()))->toBe(['reason', 'ended_at']);
});

it('lists a user own sessions and lets them end one', function (): void {
    $user = student();

    $signIn = signInFrom('laptop');

    $this->withHeader('Authorization', 'Bearer '.$signIn['token'])
        ->getJson('/api/v1/auth/sessions')->assertOk()->assertJsonCount(1);

    $session = AuthSession::query()->where('user_id', $user->getKey())->active()->sole();

    $this->withHeader('Authorization', 'Bearer '.$signIn['token'])
        ->deleteJson("/api/v1/auth/sessions/{$session->uuid}")->assertNoContent();

    expect($session->fresh()->ended_reason)->toBe(SessionEndReason::Manual);
});

it('hides another user sessions behind a 404', function (): void {
    student();
    signInFrom('laptop');

    $other = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $otherSession = AuthSession::query()->active()->sole();

    Sanctum::actingAs($other);

    // 404 rather than 403: whether a session uuid exists is itself information.
    $this->deleteJson("/api/v1/auth/sessions/{$otherSession->uuid}")->assertNotFound();
});

// FR-031. If the password changed because it leaked, leaving the intruder signed
// in defeats the change.
it('ends every other session when the password changes', function (): void {
    $user = student();

    PlatformSettings::set('auth.device_limits', ['student' => 3]);

    signInFrom('laptop');
    signInFrom('phone');
    $current = signInFrom('tablet');

    $this->withHeader('Authorization', 'Bearer '.$current['token'])
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

    $active = AuthSession::query()->where('user_id', $user->getKey())->active()->get();

    expect($active)->toHaveCount(1)
        ->and($active->first()->uuid)->toBe($current['session_uuid']);
});
