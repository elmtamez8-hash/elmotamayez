<?php

declare(strict_types=1);

use App\Modules\Analytics\Actions\ReadPlatformAnalytics;
use App\Modules\Tenancy\Support\PlatformSettings;

/*
| SC-013 — the ranking excludes anybody under the review threshold, in 100% of
| cases (FR-041).
|
| ⚠️ AND THE NAMES MUST NOT BE EMPTY. `users` has no `name` column — it is an
| accessor over `first_name`/`last_name` — so a constrained eager load naming
| `user:id,uuid,name` selects a column that does not exist and renders a blank
| name with a 200. Spec 010 shipped that spelling six times across four modules
| and none of its tests could see it, because they all read the model rather than
| the payload. This one reads the payload.
*/

it('excludes a teacher below the minimum review count', function (): void {
    PlatformSettings::set('analytics.min_reviews', 5, null);

    $workspace = marketplaceWorkspace('Academy');

    marketplaceTeacher($workspace, ['average_rating' => 5.0, 'reviews_count' => 1]);
    $seasoned = marketplaceTeacher($workspace, ['average_rating' => 4.6, 'reviews_count' => 40]);

    $top = collect(app(ReadPlatformAnalytics::class)->handle()['top_teachers']);

    // The five-star newcomer outranks everybody on average alone, which is the
    // bias the threshold exists to remove.
    expect($top)->toHaveCount(1)
        ->and($top->first()['uuid'])->toBe($seasoned->uuid);
});

it('names the teacher, and the name is not empty', function (): void {
    PlatformSettings::set('analytics.min_reviews', 1, null);

    $teacher = marketplaceTeacher(
        marketplaceWorkspace('Academy'),
        ['average_rating' => 4.9, 'reviews_count' => 12],
    );

    $top = collect(app(ReadPlatformAnalytics::class)->handle()['top_teachers']);

    expect($top->first()['name'])->not->toBe('')
        ->and($top->first()['name'])->toContain($teacher->user?->first_name ?? 'x');
});

it('ranks across workspaces, not within one', function (): void {
    PlatformSettings::set('analytics.min_reviews', 2, null);

    marketplaceTeacher(marketplaceWorkspace('Academy A'), ['average_rating' => 4.1, 'reviews_count' => 9]);
    marketplaceTeacher(marketplaceWorkspace('Academy B'), ['average_rating' => 4.8, 'reviews_count' => 9]);

    $top = collect(app(ReadPlatformAnalytics::class)->handle()['top_teachers']);

    // Two workspaces, deliberately: a read left inside the workspace scope
    // returns one of them and passes every assertion made on a single-academy
    // fixture.
    expect($top)->toHaveCount(2)
        ->and($top->first()['average_rating'])->toBe(4.8);
});

it('excludes a teacher with no rating at all rather than ranking them as zero', function (): void {
    PlatformSettings::set('analytics.min_reviews', 0, null);

    marketplaceTeacher(marketplaceWorkspace('Academy'), ['average_rating' => null, 'reviews_count' => 0]);

    expect(app(ReadPlatformAnalytics::class)->handle()['top_teachers'])->toBe([]);
});
