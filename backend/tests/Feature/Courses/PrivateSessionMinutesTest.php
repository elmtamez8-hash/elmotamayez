<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use Laravel\Sanctum\Sanctum;

/*
| Spec 023 · T025 — ⚠️ TRAP 2: the column is READ BACK FROM THE DATABASE.
|
| A column a migration adds and `$fillable` does not know about is a column that
| is NEVER WRITTEN, in silence: mass assignment discards the key with no
| exception and no log, and the endpoint answers 200. Spec 013 shipped three of
| them on `student_profiles` in one change — `date_of_birth`, `dob_is_estimated`,
| `guardian_contact` — and the guardian-consent gate that hangs on a student's
| age never fired for anyone who signed themselves up.
|
| ⚠️ EVERY ONE OF THOSE ASSERTIONS WAS CORRECT AND NONE OF THEM COULD SEE IT,
| because they were made against the RESPONSE BODY — which echoes what was
| submitted, not what was stored. So this file writes through the real endpoint
| and then asks the database.
|
| ⚠️ AND A FACTORY CANNOT SUBSTITUTE FOR THE ENDPOINT. Laravel factories run
| inside `Model::unguarded()`, so a fixture built with `Course::factory()->create([...])`
| writes the column whether or not it is fillable — a test built that way is
| green against exactly the bug it exists to catch.
*/

it('stores the private session duration, not merely echoes it', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    Sanctum::actingAs($owner);

    $course = Course::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
    ]);

    $response = $this->putJson("/api/v1/courses/{$course->uuid}", [
        'private_session_minutes' => 45,
    ]);

    $response->assertOk();

    // The claim under test. `refresh()` re-reads the row; the model in memory
    // would happily report an attribute the database never received.
    expect($course->refresh()->private_session_minutes)->toBe(45);
});

it('leaves the duration null rather than inventing one', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    Sanctum::actingAs($owner);

    $course = Course::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
    ]);

    $this->putJson("/api/v1/courses/{$course->uuid}", ['title' => 'اسم جديد'])->assertOk();

    // NULL means «the platform default», which `RequestPrivateSession` resolves
    // to sixty — never «no private sessions», and never a stored 60 that would
    // stop following the default when it moves.
    expect($course->refresh()->private_session_minutes)->toBeNull();
});

it('refuses a duration the column cannot hold', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    Sanctum::actingAs($owner);

    $course = Course::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
    ]);

    /*
    | ⚠️ SQLITE STORES ANY INTEGER IN AN `unsignedSmallInteger` AND MYSQL IN
    | STRICT MODE REJECTS IT. Without the rule this is a 200 here and a 500 on
    | the deploy — the column-width defect this repository's own notes name.
    */
    $this->putJson("/api/v1/courses/{$course->uuid}", [
        'private_session_minutes' => 100000,
    ])->assertStatus(422);

    expect($course->refresh()->private_session_minutes)->toBeNull();
});
