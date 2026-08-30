<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Shared\Support\GuardianPermission;
use Laravel\Sanctum\Sanctum;

/*
| A guardian's list of children must cost a fixed number of queries (spec 022).
|
| ⚠️ AND IT ASSERTS THE FIELD IS PRESENT AS WELL AS THAT THE COST IS FLAT.
| Those are two OPPOSITE mistakes and a budget alone catches only one of them:
| dropping the shared read altogether makes the page CHEAPER while the stage key
| goes missing, so a query-count test reports the regression as an improvement
| and the screen lists children with no year against any of them.
|
| The column is text with NO relation behind it, so `->with()` is not available
| as a fix — `SchoolYearDirectory` reads the whole map in one query and memoises
| it for the request.
*/

/** @param list<string> $years */
function guardianWithChildren(User $guardian, array $years): void
{
    foreach ($years as $index => $year) {
        ParentStudentRelation::query()->create([
            'guardian_user_id' => $guardian->getKey(),
            'student_user_id' => null,
            'student_name' => 'طفل '.$index,
            'student_age' => 10 + $index,
            // ⚠️ DIFFERENT YEARS PER ROW. All-the-same rows would be answered by
            // any per-row cache that happened to exist and the N+1 would hide.
            'student_school_year_slug' => $year,
            'relation_type' => RelationType::Parent->value,
            'permissions' => [GuardianPermission::Attendance->value],
            'status' => RelationStatus::Active->value,
        ]);
    }
}

it('costs the same for two children as for eight', function (): void {
    $small = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    $large = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    guardianWithChildren($small, ['year-1', 'year-7']);
    guardianWithChildren($large, ['year-1', 'year-2', 'year-3', 'year-4', 'year-7', 'year-9', 'year-10', 'university']);

    // A warm-up request: the first call through the app pays for route
    // resolution and the permission registrar, which is not what is being
    // measured here.
    Sanctum::actingAs($small);
    $this->getJson('/api/v1/family/relations')->assertOk();

    [$smallCost] = countingQueries(fn () => $this->getJson('/api/v1/family/relations')->assertOk());

    Sanctum::actingAs($large);
    [$largeCost, $response] = countingQueries(fn () => $this->getJson('/api/v1/family/relations')->assertOk());

    expect($largeCost)->toBeLessThanOrEqual($smallCost);

    // The other half, and the one a budget cannot see: the field is THERE.
    // Unwrapped: `JsonResource::withoutWrapping()` is on globally in this app.
    $payload = $response->json();

    expect($payload)->toHaveCount(8);

    foreach ($payload as $row) {
        expect($row)->toHaveKey('student_grade_level_slug')
            ->and($row['student_grade_level_slug'])->not->toBeNull()
            ->and($row)->toHaveKey('student_school_year_name');
    }

    // And the derivation is per-row correct, not one stage stamped on all eight.
    $stages = collect($payload)->pluck('student_grade_level_slug', 'student_school_year_slug');

    expect($stages['year-1'])->toBe('primary')
        ->and($stages['year-7'])->toBe('preparatory')
        ->and($stages['year-10'])->toBe('secondary')
        ->and($stages['university'])->toBe('university');
});
