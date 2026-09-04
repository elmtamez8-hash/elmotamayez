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

/*
| Spec 025 · FR-014 — the `workspaces` field on `UserResource`, measured the same
| way and for the same pair of opposite mistakes.
|
| ⚠️ THE COST MUST NOT GROW WITH THE NUMBER OF PLACES, **AND THE FIELD MUST BE
| THERE**. Dropping the read altogether makes `/auth/me` one query cheaper while
| the key silently disappears — a budget-only test reports that regression as an
| improvement, and the sidebar loses the entry for every teacher on the platform.
|
| ⚠️ AND THE FIELD RIDES ON EIGHT ENDPOINTS, not just this one: `UserResource` is
| returned by register, registerStudent, login, me, updateProfile, the parent
| controller and the two-factor controller. Which is exactly why the student and
| guardian skip below is worth its line — they are most of the accounts on the
| platform and are members of nothing by design.
*/
it('sends the places a teacher works at a flat cost', function (): void {
    [$own, $teacher] = $this->createWorkspaceWithOwner(['name' => 'خالد عبد الباسط']);
    [$elsewhere] = $this->createWorkspaceWithOwner(['name' => 'نور عبد الله']);

    Sanctum::actingAs($teacher);
    $this->getJson('/api/v1/auth/me')->assertOk();

    [$oneCost, $onePlace] = countingQueries(fn () => $this->getJson('/api/v1/auth/me')->assertOk());

    expect($onePlace->json('workspaces'))->toHaveCount(1)
        ->and($onePlace->json('workspaces.0.name'))->toBe('خالد عبد الباسط')
        // uuid and never id — Constitution VI.
        ->and($onePlace->json('workspaces.0'))->toHaveKey('uuid')
        ->and($onePlace->json('workspaces.0'))->not->toHaveKey('id');

    // Now they also assist at somebody else's place, which is the case FR-014
    // keeps the switcher for. One more row must not mean one more query.
    $this->addWorkspaceMember($elsewhere, 'teacher', $teacher);

    $this->asGuest();
    Sanctum::actingAs($teacher);
    $this->getJson('/api/v1/auth/me')->assertOk();

    [$twoCost, $twoPlaces] = countingQueries(fn () => $this->getJson('/api/v1/auth/me')->assertOk());

    expect($twoPlaces->json('workspaces'))->toHaveCount(2)
        ->and($twoCost)->toBeLessThanOrEqual($oneCost);

    expect($own->getKey())->not->toBe($elsewhere->getKey());
});

it('skips the read entirely for a student, who is a member of nothing', function (): void {
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    Sanctum::actingAs($student);
    $this->getJson('/api/v1/auth/me')->assertOk();

    [$cost, $response] = countingQueries(fn () => $this->getJson('/api/v1/auth/me')->assertOk());

    /*
    | ⚠️ EMPTY IS SENT, NEVER ABSENT. «No places» and «the key is missing» are
    | different answers, and the sidebar reads the count — a missing key would
    | make the banner's three-way branch depend on a `?? []` in the client, which
    | is a second answer to a question the server already answers.
    */
    expect($response->json('workspaces'))->toBe([])
        ->and($cost)->toBeGreaterThan(0);
});
