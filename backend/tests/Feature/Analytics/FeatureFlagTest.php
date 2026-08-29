<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\FeatureFlag;
use App\Modules\Tenancy\Support\Flags;
use App\Shared\Support\WorkspaceContext;

/*
| SC-014 — a feature behind a switched-off flag is invisible to everybody, and
| visible to the one workspace it was lit for. TWO OPPOSITE CASES, because either
| alone passes against a broken implementation: «nobody sees it» is true of a flag
| system that always answers false, and «this teacher sees it» is true of one that
| always answers true.
*/

beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$this->other] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    app(Flags::class)->forget();
});

it('answers false for a key with no row at all', function (): void {
    // Not an error, deliberately: a flag is created and retired far faster than
    // a migration cycle, and an unknown key that threw would make deleting a
    // retired flag a deploy-ordering problem.
    expect(app(Flags::class)->enabled('feature.unknown', null))->toBeFalse();
});

it('shows the feature to the workspace it was lit for and to nobody else', function (): void {
    FeatureFlag::factory()->create(['key' => 'feature.beta', 'enabled' => false]);
    FeatureFlag::factory()->create([
        'key' => 'feature.beta',
        'workspace_id' => $this->workspace->getKey(),
        'enabled' => true,
    ]);

    $flags = app(Flags::class);

    expect($flags->enabled('feature.beta', (int) $this->workspace->getKey()))->toBeTrue()
        ->and($flags->enabled('feature.beta', (int) $this->other->getKey()))->toBeFalse()
        ->and($flags->enabled('feature.beta', null))->toBeFalse();
});

it('lets a workspace opt OUT of a platform default that is on', function (): void {
    FeatureFlag::factory()->create(['key' => 'feature.wide', 'enabled' => true]);
    FeatureFlag::factory()->create([
        'key' => 'feature.wide',
        'workspace_id' => $this->workspace->getKey(),
        'enabled' => false,
    ]);

    $flags = app(Flags::class);

    /*
    | The row order is what makes this work: the map reads `workspace_id` ASC, so
    | the platform row (0) is written first and the override lands on top of it.
    | Read the other way round the default would win and an opt-out would be
    | silently ignored — the same switch failing in the direction nobody tests.
    */
    expect($flags->enabled('feature.wide', (int) $this->workspace->getKey()))->toBeFalse()
        ->and($flags->enabled('feature.wide', (int) $this->other->getKey()))->toBeTrue();
});

it('reads the whole map in one query however many keys are asked for', function (): void {
    FeatureFlag::factory()->create(['key' => 'feature.a', 'enabled' => true]);
    FeatureFlag::factory()->create(['key' => 'feature.b', 'enabled' => true]);
    FeatureFlag::factory()->create(['key' => 'feature.c', 'enabled' => false]);

    $flags = app(Flags::class);
    $flags->forget();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $flags->enabled('feature.a', null);
    $flags->enabled('feature.b', null);
    $flags->enabled('feature.c', null);
    $flags->enabled('feature.d', null);

    // A flag is asked from a Resource, from a policy and from inside a loop —
    // `where('key', …)` per call is an N+1 by construction.
    expect($queries)->toBe(1);
});

it('resolves a null workspace to the platform default, which is what a guest gets', function (): void {
    FeatureFlag::factory()->create(['key' => 'feature.public', 'enabled' => true]);

    app(WorkspaceContext::class)->forget();

    expect(app(Flags::class)->enabled('feature.public', null))->toBeTrue();
});
