<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\UnlockRule;
use App\Modules\Assessments\Support\UnlockReader;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Contracts\UnlockDirectory;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| SC-013 · NFR-010. FR-041 asks the gate at every request, so its cost has to be
| flat in the number of sessions on the screen.
*/

/** @return list<int> */
function timetable(object $workspace, object $student, object $course, int $count): array
{
    $ids = [];

    foreach (range(1, $count) as $index) {
        $session = ClassSession::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'status' => ClassSessionStatus::Completed,
            'starts_at' => now()->subWeeks($count + 1 - $index),
            'ends_at' => now()->subWeeks($count + 1 - $index)->addHour(),
        ]);

        attendanceRow($workspace, $session, $student, AttendanceStatus::Present);

        $ids[] = (int) $session->getKey();
    }

    return $ids;
}

it('costs the same for three sessions as for a full page, and stays under the cap', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [, , $course] = gatedPair($workspace, $student);

    UnlockRule::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'requires_attendance' => true,
        'requires_assignment' => true,
    ]);

    $all = timetable($workspace, $student, $course, 20);

    $directory = app(UnlockDirectory::class);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $directory->refusalsFor($student, array_slice($all, 0, 3));
    $small = count(DB::getQueryLog());

    DB::flushQueryLog();
    $directory->refusalsFor($student, $all);
    $large = count(DB::getQueryLog());
    DB::disableQueryLog();

    /*
    | ⚠️ EQUALITY AND A CAP, BOTH. Equality alone is nearly vacuous — a per-row
    | implementation on a PAGINATED path grows with the page, and the page size
    | is fixed, so two runs of the same size would agree while every row cost a
    | query. The cap is what says the constant is small. And equality is what
    | says it is CONSTANT: a cap alone passes at 14 queries for 3 sessions and
    | fails silently at 40.
    */
    expect($large)->toBe($small)
        ->and($large)->toBeLessThanOrEqual(15);
});

it('stamps a whole collection without asking per row', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [, , $course] = gatedPair($workspace, $student);

    UnlockRule::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'requires_attendance' => true,
        'requires_assignment' => false,
    ]);

    $ids = timetable($workspace, $student, $course, 20);
    $sessions = ClassSession::query()->whereIn('id', $ids)->get();

    DB::enableQueryLog();
    DB::flushQueryLog();

    app(UnlockReader::class)->stamp($sessions, $student);

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    /*
    | ⚠️ THIS CLASS EXISTS SO A RESOURCE DOES NOT ASK PER ROW. A Resource runs
    | once per row, so twenty sessions asking individually is six queries times
    | twenty on the screen a student opens first every morning — the exact shape
    | `WithholdingReader::stamp()` was written for in 006.
    */
    expect($queries)->toBeLessThanOrEqual(15)
        ->and($sessions->first()->getAttribute('unlock_open'))->toBeBool();
});

it('stamps the session list endpoint without asking per row', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [, , $course] = gatedPair($workspace, $student);

    UnlockRule::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'requires_attendance' => true,
        'requires_assignment' => false,
    ]);

    timetable($workspace, $student, $course, 3);

    Sanctum::actingAs($student);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $response = $this->getJson('/api/v1/class-sessions')->assertOk();
    $small = count(DB::getQueryLog());

    timetable($workspace, $student, $course, 17);

    DB::flushQueryLog();
    $this->getJson('/api/v1/class-sessions')->assertOk();
    $large = count(DB::getQueryLog());
    DB::disableQueryLog();

    /*
    | ⚠️ FLATNESS, NOT AN ABSOLUTE CAP — the shape 005's own QueryBudgetTest uses
    | on this endpoint, and for its stated reason: the first request also warms
    | the permission cache, so a bigger list legitimately costs no more and
    | sometimes less. The absolute number lives on the gate itself, above.
    |
    | And the endpoint is tested as well as the reader because `UnlockReader` was
    | built, tested and reached by NOTHING for an hour — a helper with no caller
    | proves the arithmetic and none of the wiring. This is what fails the day
    | somebody drops the stamp from the controller and the Resource goes back to
    | asking per row.
    */
    expect($large)->toBeLessThanOrEqual($small)
        ->and($response->json('data.0.unlock_open'))->not->toBeNull();
});
