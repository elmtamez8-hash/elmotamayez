<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ THE IDEMPOTENCY REPLAY WAS KEYED ON THE CLIENT'S HEADER ALONE.
|
| `method|path|Idempotency-Key` — so anybody who sent the same key value to the
| same path was handed the first requester's response, and on the signup routes
| that response carried a freshly minted Sanctum token. A key is a value the
| client chooses; a predictable one is shared by construction.
|
| A throwaway route stands in for the real ones so each case can count how many
| times the handler actually ran: a replay is a response the handler did not
| produce.
*/

beforeEach(function (): void {
    $this->runs = 0;

    Route::middleware(['api', 'idempotent'])->post('/api/__idempotency-probe', function () {
        $this->runs++;

        return response()->json([
            'run' => $this->runs,
            'token' => 'secret-'.$this->runs,
            'user' => ['uuid' => 'u-1', 'token' => 'nested-'.$this->runs],
        ], 201);
    });
});

function idempotencyProbe(string $ip, string $key = 'same-key'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/__idempotency-probe', [], ['Idempotency-Key' => $key]);
}

it('replays a repeated key from the same guest', function (): void {
    idempotencyProbe('41.200.1.7')->assertCreated();
    $second = idempotencyProbe('41.200.1.7')->assertCreated();

    expect($this->runs)->toBe(1)
        ->and($second->headers->get('Idempotent-Replay'))->toBe('true')
        ->and($second->json('run'))->toBe(1);
});

it('does not hand one guest\'s response to another guest who sends the same key', function (): void {
    idempotencyProbe('41.200.1.7')->assertCreated();
    $stranger = idempotencyProbe('41.200.1.8')->assertCreated();

    expect($this->runs)->toBe(2)
        ->and($stranger->headers->has('Idempotent-Replay'))->toBeFalse()
        ->and($stranger->json('run'))->toBe(2);
});

it('does not hand one account\'s response to another account behind the same address', function (): void {
    Sanctum::actingAs(User::factory()->create());
    idempotencyProbe('41.200.1.7')->assertCreated();

    Sanctum::actingAs(User::factory()->create());
    $other = idempotencyProbe('41.200.1.7')->assertCreated();

    expect($this->runs)->toBe(2)
        ->and($other->json('run'))->toBe(2);
});

it('never replays a token, at any depth', function (): void {
    $first = idempotencyProbe('41.200.1.7')->assertCreated();
    $replay = idempotencyProbe('41.200.1.7')->assertCreated();

    // The original response is untouched — only the stored copy is stripped.
    expect($first->json('token'))->toBe('secret-1')
        ->and($replay->headers->get('Idempotent-Replay'))->toBe('true')
        ->and($replay->json())->not->toHaveKey('token')
        ->and($replay->json('user'))->toBe(['uuid' => 'u-1']);
});
