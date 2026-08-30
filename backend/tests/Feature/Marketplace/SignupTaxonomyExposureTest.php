<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\SchoolYear;
use App\Modules\Marketplace\Support\PublicFieldAllowlist;

/*
| The three signup reads, walked field by field (spec 022 · T026 · T027).
|
| ⚠️ `PublicExposureTest` WILL NEVER SEE THESE ROUTES. It walks a list of urls
| written by hand in that file, so a new public payload is invisible to it — the
| guard has to be written here or it does not exist.
|
| ⚠️ ASCII NEEDLES OR STRUCTURE, NEVER ARABIC TEXT. `getContent()` escapes
| non-ASCII, so `not->toContain('الرياضيات')` is true of a payload that publishes
| it.
*/

beforeEach(function (): void {
    $this->asGuest();
});

it('publishes only the fields a signup picker needs', function (): void {
    foreach (['subjects', 'grade-levels', 'school-years'] as $path) {
        $payload = $this->getJson("/api/v1/signup/{$path}")->assertOk()->json();

        expect($payload)->not->toBeEmpty();

        foreach ($payload as $row) {
            foreach (array_keys($row) as $key) {
                expect(PublicFieldAllowlist::SIGNUP_TAXONOMY)->toContain($key)
                    ->and(PublicFieldAllowlist::FORBIDDEN)->not->toContain($key);
            }
        }
    }
});

it('publishes neither the row id nor the uuid', function (): void {
    foreach (['subjects', 'grade-levels', 'school-years'] as $path) {
        $body = $this->getJson("/api/v1/signup/{$path}")->getContent();

        expect($body)->not->toContain('"id"')
            ->not->toContain('"uuid"')
            ->not->toContain('"teachers_count"');
    }
});

it('offers the whole vocabulary with not one publicly listed teacher', function (): void {
    // SC-002. No workspace, no teacher profile, nothing published — which is the
    // state of a brand new deployment, and the state in which the marketplace
    // reads correctly answer `[]`.
    expect($this->getJson('/api/v1/signup/subjects')->json())->not->toBeEmpty()
        ->and($this->getJson('/api/v1/signup/grade-levels')->json())->not->toBeEmpty()
        ->and($this->getJson('/api/v1/signup/school-years')->json())->not->toBeEmpty();
});

it('does not serve the marketplace answer out of the signup key', function (): void {
    /*
     | ⚠️ THE MARKETPLACE READ IS WARMED FIRST, AND THE ORDER IS THE TEST.
     |
     | `MarketplaceCache::key()` is a FLAT namespace — `marketplace:v{N}:{suffix}`
     | — and `ListPublicTaxonomy` owns the suffixes `subjects` and `grade_levels`
     | literally. Without the `signup:` prefix the two reads share a key, and
     | whichever ran first answers for both. Warming the signup read first would
     | pass over exactly that bug.
     */
    expect($this->getJson('/api/v1/marketplace/subjects')->assertOk()->json())->toBe([]);
    expect($this->getJson('/api/v1/marketplace/grade-levels')->assertOk()->json())->toBe([]);

    expect($this->getJson('/api/v1/signup/subjects')->json())->not->toBeEmpty()
        ->and($this->getJson('/api/v1/signup/grade-levels')->json())->not->toBeEmpty();
});

it('drops a year whose own stage was retired, without writing to the year', function (): void {
    // The derived predicate (FR-003): "actually on offer" is read, never stored.
    GradeLevel::query()->where('slug', 'secondary')->update(['is_active' => false]);

    $slugs = collect($this->getJson('/api/v1/signup/school-years')->json())->pluck('slug');

    expect($slugs)->not->toContain('year-10')
        ->and($slugs)->toContain('year-1');

    // Nothing was written to the year itself — re-enabling the stage brings it
    // back, which a cascading deactivation could not.
    expect(SchoolYear::query()->where('slug', 'year-10')->value('is_active'))->toBeTruthy();
});

it('carries the stage each year belongs to', function (): void {
    $years = collect($this->getJson('/api/v1/signup/school-years')->json())
        ->keyBy('slug');

    expect($years['year-10']['grade_level_slug'])->toBe('secondary')
        ->and($years['year-3']['grade_level_slug'])->toBe('primary')
        ->and($years['university']['grade_level_slug'])->toBe('university');
});
