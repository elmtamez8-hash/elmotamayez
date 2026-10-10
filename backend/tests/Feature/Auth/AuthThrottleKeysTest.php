<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| Security scan 2026-10-10 — F4 · F6 · F16. Each limiter keyed on something the
| caller writes was a fresh bucket per spelling; these hold the key on the
| account itself.
*/

it('counts every letter case of one email in one login bucket', function (): void {
    User::factory()->create(['email' => 'victim@example.com', 'password' => 'password']);

    foreach (['victim@example.com', 'Victim@example.com', 'vIctim@example.com', 'viCtim@example.com', 'vicTim@example.com'] as $index => $email) {
        // A new address per try: the per-IP bucket is not what is under test.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.($index + 1)])
            ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'wrong'])
            ->assertStatus(422);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->postJson('/api/v1/auth/login', ['email' => 'VICTIM@example.com', 'password' => 'wrong'])
        ->assertStatus(429);
});

it('bounds current-password guesses on the profile door per account', function (): void {
    $user = User::factory()->create(['password' => 'password']);
    Sanctum::actingAs($user);

    foreach (range(1, 5) as $_) {
        $this->patchJson('/api/v1/auth/me', ['email' => 'new@example.com', 'current_password' => 'guess'])
            ->assertStatus(422);
    }

    $this->patchJson('/api/v1/auth/me', ['email' => 'new@example.com', 'current_password' => 'guess'])
        ->assertStatus(429);
});

it('keys the signed-in two-factor doors on the account, not on a challenge field the caller sends', function (): void {
    $user = User::factory()->create(['password' => 'password']);
    Sanctum::actingAs($user);

    foreach (range(1, 5) as $index) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.'.$index])
            ->deleteJson('/api/v1/auth/2fa', ['current_password' => 'password', 'code' => '000000', 'challenge' => (string) Str::uuid()])
            ->assertStatus(422);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.99'])
        ->deleteJson('/api/v1/auth/2fa', ['current_password' => 'password', 'code' => '000000', 'challenge' => (string) Str::uuid()])
        ->assertStatus(429);
});
