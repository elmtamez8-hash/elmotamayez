<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/*
| `personal_access_tokens.last_used_at` is written at most once every five
| minutes per token, not on every request (`StampTokenLastUsed`).
|
| ⚠️ A REAL SIGN-IN AND A REAL BEARER HEADER. `Sanctum::actingAs()` never runs
| the guard, so neither Sanctum's stamp nor ours would fire and this file would
| prove nothing. `forgetGuards()` between requests for the same reason: the
| guard caches the resolved user for the life of the application instance.
*/
it('stamps last_used_at on first use, skips the write inside five minutes, and stamps again after', function (): void {
    User::factory()->create(['email' => 'stamp@example.com', 'password' => 'password']);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'stamp@example.com',
        'password' => 'password',
    ])->assertOk()->json('token');

    $row = fn (): PersonalAccessToken => PersonalAccessToken::query()->latest('id')->firstOrFail();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    $first = $row()->last_used_at;

    expect($first)->not->toBeNull();

    // Inside the window: the column does not move.
    $this->travel(2)->minutes();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    expect($row()->last_used_at?->equalTo($first))->toBeTrue();

    // Past the window: it does.
    $this->travel(4)->minutes();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    expect($row()->last_used_at?->gt($first))->toBeTrue();
});
