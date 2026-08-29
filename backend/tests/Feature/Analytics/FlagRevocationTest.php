<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\FeatureFlag;
use App\Modules\Tenancy\Support\Flags;
use Illuminate\Support\Facades\DB;

/*
| FR-048 — an extinguished flag takes effect NOW, and the binding is what decides
| whether that is true on a queue worker.
|
| ⚠️ THIS IS MEASURED WITH TWO CONTAINER RESOLUTIONS AND A `forgetScopedInstances()`
| BETWEEN THEM, NEVER WITH TWO HTTP REQUESTS. Laravel calls that hook from the
| queue worker's own loop and from the HTTP kernel between requests — inside ONE
| test process, `scoped()` and `singleton()` are indistinguishable without calling
| it, so a two-request test passes against a `singleton()` binding and the defect
| ships: a worker that booted at noon keeps a flag alight until it restarts.
|
| ⚠️ AND IT MUST NOT CALL `Flags::forget()`. That empties the memo directly, so
| the assertion would pass under BOTH bindings — a test that cannot fail for the
| reason it exists.
*/

beforeEach(function (): void {
    FeatureFlag::factory()->create(['key' => 'feature.live', 'enabled' => true]);
});

it('forgets the memo when the worker recycles its scoped instances', function (): void {
    expect(app(Flags::class)->enabled('feature.live', null))->toBeTrue();

    // The switch is thrown at the database, exactly as the panel does it.
    DB::table('feature_flags')->where('key', 'feature.live')->update(['enabled' => false]);

    // What a queue worker does between two jobs, and the HTTP kernel between two
    // requests. Under `singleton()` this changes nothing and the flag stays lit.
    app()->forgetScopedInstances();

    expect(app(Flags::class)->enabled('feature.live', null))->toBeFalse();
});

it('keeps one instance for the length of a single request', function (): void {
    $first = app(Flags::class);
    $second = app(Flags::class);

    // Not `bind()`: a fresh instance per resolution re-runs the query every time
    // it is asked, and this is asked dozens of times in one page.
    expect($first)->toBe($second);
});

it('does not answer from a memo built before the row existed', function (): void {
    expect(app(Flags::class)->enabled('feature.new', null))->toBeFalse();

    FeatureFlag::factory()->create(['key' => 'feature.new', 'enabled' => true]);

    app()->forgetScopedInstances();

    expect(app(Flags::class)->enabled('feature.new', null))->toBeTrue();
});
