<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ THE ADDRESS IS WHERE THE RESET LINK GOES, SO CHANGING IT IS CHANGING WHO OWNS
| THE ACCOUNT.
|
| `PATCH /auth/me` took `email` under a bearer token alone: whoever held a stolen
| token for one minute could point the account at their own inbox, ask for a
| reset link and keep the account for ever — while `email_verified_at` went on
| vouching for an address nobody had proved, and nobody was told.
*/
function emailChangeAccount(): User
{
    return User::factory()->create([
        'email' => 'huda@example.com',
        'password' => 'correct-horse',
        'email_verified_at' => now(),
    ]);
}

function securityAlertsFor(User $user): int
{
    return Notification::query()
        ->where('recipient_user_id', $user->getKey())
        ->where('type', NotificationType::SecurityAlert->value)
        ->count();
}

it('refuses a new address without the current password', function (): void {
    $user = Sanctum::actingAs(emailChangeAccount());

    $this->patchJson('/api/v1/auth/me', ['email' => 'thief@example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['current_password']);

    expect($user->fresh()->email)->toBe('huda@example.com')
        ->and($user->fresh()->email_verified_at)->not->toBeNull()
        ->and(securityAlertsFor($user))->toBe(0);
});

it('refuses a new address with the wrong password', function (): void {
    $user = Sanctum::actingAs(emailChangeAccount());

    $this->patchJson('/api/v1/auth/me', [
        'email' => 'thief@example.com',
        'current_password' => 'guess',
    ])->assertStatus(422)->assertJsonValidationErrors(['current_password']);

    expect($user->fresh()->email)->toBe('huda@example.com');
});

it('moves the address, drops its verification and tells the owner', function (): void {
    $user = Sanctum::actingAs(emailChangeAccount());

    $this->patchJson('/api/v1/auth/me', [
        'email' => 'huda.new@example.com',
        'current_password' => 'correct-horse',
    ])->assertOk()->assertJsonPath('email', 'huda.new@example.com');

    $fresh = $user->fresh();

    // Verification answered for the OLD address; nobody has proved the new one.
    expect($fresh->email)->toBe('huda.new@example.com')
        ->and($fresh->email_verified_at)->toBeNull()
        ->and(securityAlertsFor($user))->toBe(1);
});

it('asks nothing of a name change', function (): void {
    $user = Sanctum::actingAs(emailChangeAccount());

    $this->patchJson('/api/v1/auth/me', ['first_name' => 'هدى', 'email' => 'huda@example.com'])
        ->assertOk();

    expect($user->fresh()->first_name)->toBe('هدى')
        ->and($user->fresh()->email_verified_at)->not->toBeNull()
        ->and(securityAlertsFor($user))->toBe(0);
});

// «Huda@Example.com» and «huda@example.com» are one inbox — asking for a
// password over a capital letter is a punishment, not a guard.
it('treats a change of case as no change', function (): void {
    $user = Sanctum::actingAs(emailChangeAccount());

    $this->patchJson('/api/v1/auth/me', ['email' => 'Huda@Example.com'])->assertOk();

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and(securityAlertsFor($user))->toBe(0);
});
