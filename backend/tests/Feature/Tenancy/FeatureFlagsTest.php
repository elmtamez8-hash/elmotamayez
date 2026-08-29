<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Flags;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Spec 011 · T028 — the sentinel and the override, which are the two things that
| fail in silence.
|
| ⚠️ THE `0` ROW IS NOT DECORATION. Written nullable, `NULL != NULL` in a unique
| index and two platform defaults for one key coexist — the reader then returns
| whichever the engine hands back first, and it can differ between two requests.
| The case below asserts the unique index actually bites on the default row,
| which is the assertion a nullable column would fail.
*/

function flags(): Flags
{
    return app(Flags::class);
}

function writeFlag(string $key, bool $enabled, int $workspaceId = Flags::PLATFORM): void
{
    // Written by hand rather than through a model: the table deliberately has no
    // `BelongsToWorkspace`, so a factory would have to say so anyway.
    DB::table('feature_flags')->insert([
        'uuid' => (string) Str::uuid(),
        'key' => $key,
        'workspace_id' => $workspaceId,
        'enabled' => $enabled,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('answers false for a key nobody has written', function (): void {
    // An unknown flag is off, never an error: a flag is created and retired far
    // faster than a migration, and a reader asking about one that has not landed
    // yet must not take the page down.
    expect(flags()->enabled('store.printed_items', 1))->toBeFalse();
});

it('reads the platform default for a workspace with no row of its own', function (): void {
    writeFlag('store.printed_items', true);

    expect(flags()->enabled('store.printed_items', 77))->toBeTrue();
});

it('lets a workspace row override the platform default in both directions', function (): void {
    writeFlag('store.printed_items', true);
    writeFlag('store.printed_items', false, workspaceId: 77);

    writeFlag('plans.enabled', false);
    writeFlag('plans.enabled', true, workspaceId: 77);

    // ⚠️ BOTH DIRECTIONS. Ordering the read wrong makes the platform row win,
    // and a test that only turns a feature ON for one workspace passes against
    // that bug — the default was off, so the answer is right for the wrong
    // reason.
    expect(flags()->enabled('store.printed_items', 77))->toBeFalse()
        ->and(flags()->enabled('plans.enabled', 77))->toBeTrue()
        // …and the workspace row must not leak to its neighbour.
        ->and(flags()->enabled('store.printed_items', 78))->toBeTrue()
        ->and(flags()->enabled('plans.enabled', 78))->toBeFalse();
});

it('gives a null workspace the platform default', function (): void {
    writeFlag('store.printed_items', true);

    // `(int) null === 0`, which for a READ is the right answer: a null context
    // is a guest, and a guest gets the platform's decision.
    expect(flags()->enabled('store.printed_items', null))->toBeTrue();
});

it('refuses a second platform default for one key', function (): void {
    writeFlag('store.printed_items', true);

    // The whole reason the sentinel is `0` and not `null`.
    expect(fn () => writeFlag('store.printed_items', false))
        ->toThrow(QueryException::class);
});

it('reads both scopes in a single query however many keys are asked', function (): void {
    writeFlag('a', true);
    writeFlag('b', true);
    writeFlag('c', true, workspaceId: 77);

    DB::enableQueryLog();

    flags()->enabled('a', 77);
    flags()->enabled('b', 77);
    flags()->enabled('c', 77);

    // One map, memoised. Per-key reads are the N+1 this class exists to avoid,
    // and they would pass every assertion above.
    expect(DB::getQueryLog())->toHaveCount(1);

    DB::disableQueryLog();
});
