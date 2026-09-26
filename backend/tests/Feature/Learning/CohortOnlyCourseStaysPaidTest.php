<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Models\Plan;
use App\Shared\Support\WorkspaceContext;

/*
| ٠٣٦ · T111 · SC-010 — a course sold ONLY through its groups is not free.
|
| ⛔ THE DEFECT IS SILENT AND GIVES THE TEACHER'S WORK AWAY. «Does this course
| need paying for» used to be answered by its own price plus any plan naming the
| COURSE or the WHOLE WORKSPACE. A teacher who prices per group has none of those
| — so the free-enrolment door read «no price attached», wrote an enrolment, and
| handed the curriculum to whoever pressed the button. No order, no subscription,
| nothing in a log, and a 201.
|
| ⚠️ THE COURSE'S OWN PRICE IS ZERO ON PURPOSE. A priced course answers on the
| column one line earlier and never reaches the plan question at all, so a
| fixture with a price passes with the defect fully present.
|
| ⚠️ AND THE REFUSAL IS ASSERTED BY ITS CODE PLUS THE ABSENCE OF THE ROW. A 422
| with an enrolment written behind it is the same outcome wearing a better
| status.
*/
beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->teacher->getKey(),
            'course_type' => Course::TYPE_GROUP,
            // Free by the column. The plans are the only thing that makes this
            // course cost anything.
            'price_minor' => 0,
        ]),
    );

    $this->cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->getKey(),
        'name' => 'مجموعة السبت',
    ]);

    $this->student = User::factory()->create(['last_workspace_id' => null]);
});

it('refuses free enrolment on a course whose only price names a group', function (): void {
    // ⛔ THE PLAN NAMES THE GROUP, not the course and not the workspace — which
    // is exactly the shape the old reader could not see.
    Plan::factory()->forCohort((string) $this->cohort->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    $this->actingAs($this->student, 'sanctum')
        ->postJson('/api/v1/courses/'.$this->course->uuid.'/enroll')
        ->assertStatus(422)
        ->assertJsonPath('code', 'purchase_required');

    expect(Enrollment::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('still lets a genuinely free course be enrolled in', function (): void {
    /*
    | ⚠️ THE POSITIVE CONTROL, AND IT IS THE HALF THAT COULD BREAK. Widening «is
    | this sold» is a refusal, and a refusal written one condition too wide shuts
    | the free door on every course on the platform — which is the mirror defect,
    | costing the teacher every student who would have walked in.
    |
    | ⛔ «Genuinely free» is the teacher's explicit flag since 2026-09-25 — a
    | course at price 0 with no plan is NOT free unless its teacher said so.
    */
    $this->course->forceFill(['is_free_enrollment' => true])->save();

    $this->actingAs($this->student, 'sanctum')
        ->postJson('/api/v1/courses/'.$this->course->uuid.'/enroll')
        ->assertStatus(201);

    expect(Enrollment::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('is not fooled by another teacher group plan', function (): void {
    /*
    | ⛔ THE TENANT PIN ON THE MONEY PATH. A plan of workspace ب naming ب's own
    | group must not make أ's course read as «sold» — that would shut the free
    | door on a course nobody ever priced, from a row its teacher cannot see.
    */
    [$otherWorkspace, $otherTeacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية ب']);

    $otherCourse = app(WorkspaceContext::class)->forWorkspace(
        $otherWorkspace,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => $otherWorkspace->getKey(),
            'created_by' => $otherTeacher->getKey(),
            'course_type' => Course::TYPE_GROUP,
        ]),
    );

    $otherCohort = Cohort::factory()->create([
        'workspace_id' => $otherWorkspace->getKey(),
        'course_id' => $otherCourse->getKey(),
        'created_by' => $otherTeacher->getKey(),
    ]);

    Plan::factory()->forCohort((string) $otherCohort->uuid)->create([
        'workspace_id' => $otherWorkspace->getKey(),
    ]);

    // Free by its teacher's explicit flag (2026-09-25).
    $this->course->forceFill(['is_free_enrollment' => true])->save();

    $this->actingAs($this->student, 'sanctum')
        ->postJson('/api/v1/courses/'.$this->course->uuid.'/enroll')
        ->assertStatus(201);
});
