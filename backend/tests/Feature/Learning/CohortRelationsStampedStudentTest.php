<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ `GET /courses/{uuid}/cohorts` WAS A 500 FOR A STUDENT STAMPED ELSEWHERE.
|
| `CohortMembership::cohort()` and `CohortTransferRequest::toCohort()/fromCohort()`
| ran under `WorkspaceScope`, and `WorkspaceContext::id()` falls back to
| `users.last_workspace_id`. A student teacher A once added to their workspace,
| who then enrolled with teacher B, read B's groups through A's workspace: the
| membership query itself is unscoped, the eager-loaded cohort came back null,
| and `$membership->cohort->uuid` threw — the whole picker, «my group», the old
| groups and the pending transfer, gone at once.
|
| BOTH shapes: the null-context student is what every other fixture builds, and
| the scope is inert for them. How it bites: remove the bypass from
| `CohortMembership::cohort()` ⇒ the stamped case answers 500; from
| `CohortTransferRequest::toCohort()` ⇒ `pending_request.to_cohort` is null.
*/

beforeEach(function (): void {
    [$this->workspaceA] = $this->createWorkspaceWithOwner();
    [$this->workspaceB, $this->teacherB] = $this->createWorkspaceWithOwner();

    $workspaceId = (int) $this->workspaceB->getKey();

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $workspaceId,
        'created_by' => $this->teacherB->getKey(),
    ]);

    $this->student = User::factory()->create();

    Enrollment::create([
        'workspace_id' => $workspaceId,
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);

    $cohortFor = fn (string $name): Cohort => Cohort::factory()->create([
        'workspace_id' => $workspaceId,
        'course_id' => $this->course->getKey(),
        'name' => $name,
        'created_by' => $this->teacherB->getKey(),
    ]);

    $current = $cohortFor('CURRENT-GROUP');
    $old = $cohortFor('OLD-GROUP');
    $target = $cohortFor('TARGET-GROUP');

    $membershipFor = fn (Cohort $cohort) => CohortMembership::factory()->state([
        'workspace_id' => $workspaceId,
        'cohort_id' => $cohort->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
    ]);

    $membershipFor($old)->closed()->create();
    $membershipFor($current)->create();

    CohortTransferRequest::factory()->create([
        'workspace_id' => $workspaceId,
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'to_cohort_id' => $target->getKey(),
        'from_cohort_id' => $current->getKey(),
    ]);
});

dataset('cohort reader shapes', [
    'null context' => [false],
    'stamped elsewhere' => [true],
]);

it('answers the picker with the reader\'s own groups named', function (bool $stamped): void {
    if ($stamped) {
        $this->student->forceFill(['last_workspace_id' => $this->workspaceA->getKey()])->save();
    }

    Sanctum::actingAs($this->student);
    app()->forgetInstance(WorkspaceContext::class);

    expect(app(WorkspaceContext::class)->id())
        ->toBe($stamped ? (int) $this->workspaceA->getKey() : null);

    $this->getJson("/api/v1/courses/{$this->course->uuid}/cohorts")
        ->assertOk()
        ->assertJsonPath('membership.cohort_name', 'CURRENT-GROUP')
        ->assertJsonPath('past_cohorts.0.name', 'OLD-GROUP')
        ->assertJsonPath('pending_request.to_cohort.name', 'TARGET-GROUP')
        ->assertJsonPath('pending_request.from_cohort.name', 'CURRENT-GROUP');
})->with('cohort reader shapes');
