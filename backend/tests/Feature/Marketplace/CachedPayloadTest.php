<?php

declare(strict_types=1);

use App\Modules\Marketplace\Http\Resources\PublicTeacherCardResource;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;

/*
| A payload that goes into a cache must be data, not objects.
|
| `JsonResource::resolve()` runs `toArray()` and stops — it does NOT recurse. A
| nested `AnonymousResourceCollection` left in the returned array renders
| correctly anyway, because `json_encode` walks `JsonSerializable` on the way
| out. So the defect is invisible on a cache MISS and on every response
| assertion: `PublicMarketplaceController::teachers()` caches the resolved array,
| the store serializes the object whole, and the next HIT answers anonymous
| visitors with
|
|   "subjects": {"__PHP_Incomplete_Class_Name": "Illuminate\\Http\\Resources\\..."}
|
| — internal class paths published, and every subject chip gone from every card
| for the rest of the TTL.
|
| ⚠️ THE OBVIOUS TEST CANNOT SEE THIS. The test cache store is `array`, which
| keeps PHP values as they are and never serializes, so hitting the endpoint
| twice passes against the bug. What is asserted instead is the invariant that
| does not depend on the store: the resolved payload contains no objects at any
| depth.
*/

/** @return list<string> Where an object survives resolve(), by path. */
function objectsInPayload(mixed $value, string $path = 'root'): array
{
    if (is_object($value)) {
        return [$path.' is '.$value::class];
    }

    if (! is_array($value)) {
        return [];
    }

    $found = [];

    foreach ($value as $key => $child) {
        $found = [...$found, ...objectsInPayload($child, $path.'.'.$key)];
    }

    return $found;
}

/*
 * Taxonomy fixtures of its own rather than the ones in PublicTeacherListTest:
 * a function declared in a sibling test file only exists once Pest has loaded
 * that file, so borrowing it works for the whole suite and fails the moment
 * anyone runs this file alone — which is exactly when a guard test is read.
 */
function cachedPayloadSubject(TeacherProfile $teacher, string $slug, string $name): void
{
    app(WorkspaceContext::class)->forWorkspace($teacher->workspace_id, function () use ($teacher, $slug, $name): void {
        $subject = Subject::query()->firstOrCreate(
            ['slug' => $slug],
            ['name_ar' => $name],
        );

        $teacher->subjects()->attach($subject->getKey());
    });
}

function cachedPayloadGradeLevel(TeacherProfile $teacher, string $slug, string $name): void
{
    app(WorkspaceContext::class)->forWorkspace($teacher->workspace_id, function () use ($teacher, $slug, $name): void {
        // Keyed on the SLUG alone: `grade_levels` lost its `workspace_id` in
        // spec 009 when the taxonomy became platform reference data, so a
        // lookup naming that column matched nothing and inserted a duplicate —
        // harmless while the table was empty, a `unique(slug)` violation now
        // that the catalogue is seeded before every test (spec 022).
        $level = GradeLevel::query()->firstOrCreate(
            ['slug' => $slug],
            ['name_ar' => $name],
        );

        $teacher->gradeLevels()->syncWithoutDetaching([$level->getKey()]);
    });
}

beforeEach(function () {
    $this->workspace = marketplaceWorkspace('Academy');
});

it('resolves nested taxonomies, so a cached card survives its store', function () {
    $teacher = marketplaceTeacher($this->workspace);

    cachedPayloadSubject($teacher, 'math', 'الرياضيات');
    cachedPayloadGradeLevel($teacher, 'secondary', 'المرحلة الثانوية');

    $profiles = TeacherProfile::query()
        ->withoutGlobalScopes()
        ->with(['user', 'subjects', 'gradeLevels', 'availabilitySlots'])
        ->whereKey($teacher->getKey())
        ->get()
        ->all();

    $payload = PublicTeacherCardResource::collection($profiles)->resolve();

    expect(objectsInPayload($payload))->toBe([]);

    // And the data is still there — a resolve that returned empty arrays would
    // also pass the assertion above.
    // `icon` comes from the seeded catalogue now — the fixture attaches an
    // existing row rather than inventing one (spec 022).
    expect($payload[0]['subjects'])->toBe([
        ['slug' => 'math', 'name_ar' => 'الرياضيات', 'icon' => 'calculator'],
    ]);
    expect($payload[0]['grade_levels'])->toBe([
        ['slug' => 'secondary', 'name_ar' => 'المرحلة الثانوية', 'icon' => null],
    ]);
});

it('omits a taxonomy that was not eager loaded rather than resolving over it', function () {
    $teacher = marketplaceTeacher($this->workspace);

    cachedPayloadSubject($teacher, 'math', 'الرياضيات');

    $bare = TeacherProfile::query()
        ->withoutGlobalScopes()
        ->whereKey($teacher->getKey())
        ->firstOrFail();

    $payload = PublicTeacherCardResource::make($bare)->resolve();

    // `whenLoaded` with a callback is what makes this an omission. The bare form
    // hands `::collection()` a MissingValue, which gets wrapped into a one-item
    // collection and resolved as if it were a taxonomy row.
    expect($payload)->not->toHaveKey('subjects')
        ->and($payload)->not->toHaveKey('grade_levels');
});
