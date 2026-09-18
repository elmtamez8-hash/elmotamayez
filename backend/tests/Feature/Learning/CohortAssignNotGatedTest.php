<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;

/*
| ٠٣٦ · T036 · FR-030 — ADMINISTRATION may still put a student into a group no
| price reaches.
|
| ⛔ THE GATE IS ABOUT WHAT A STUDENT MAY BUY THEMSELVES INTO, AND NOTHING ELSE.
| A teacher placing a student is not a sale: the money was settled elsewhere, or
| there was none, and refusing here would take the teacher's own control over
| their own room away over a plan the platform has not priced. ٠٣٤ · FR-030
| already separated «assignable» from «joinable» for exactly this reason, and
| this is that separation asked one requirement later.
|
| ⚠️ THE WRITE IS WHAT IS ASSERTED, NOT THE STATUS CODE. A 201 is equally true of
| a handler that answered and did nothing; the membership row is the claim. This
| case passes today and after a correct implementation, and fails only on a wrong
| one — which is the whole shape of an exemption test.
*/
beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->teacher->getKey(),
            'course_type' => Course::TYPE_GROUP,
        ]),
    );

    // ⛔ NO PLAN ANYWHERE, deliberately. This group is exactly the one a student
    // may not join, and the point is that a teacher still may place somebody in
    // it.
    $this->cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->getKey(),
        'name' => 'مجموعة بلا سعر',
    ]);

    $this->student = User::factory()->create(['last_workspace_id' => null]);

    Enrollment::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);
});

it('lets a teacher place a student into a group no price reaches', function (): void {
    $this->actingAs($this->teacher, 'sanctum')
        ->postJson('/api/v1/manage/cohorts/'.$this->cohort->uuid.'/members', [
            'student_uuid' => (string) $this->student->uuid,
        ])
        ->assertStatus(201);

    $membership = CohortMembership::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())
        ->whereNull('closed_at')
        ->first();

    expect($membership)->not->toBeNull()
        ->and((int) $membership->cohort_id)->toBe((int) $this->cohort->getKey());
});

it('still refuses the same group to the student themselves', function (): void {
    /*
    | ⚠️ THE OTHER HALF, IN THE SAME FILE. «Assignment works» is unremarkable on a
    | build with no gate at all; what makes it an EXEMPTION is that the student's
    | own door refuses the identical group in the identical fixture.
    */
    $this->actingAs($this->student, 'sanctum')
        ->postJson('/api/v1/cohorts/'.$this->cohort->uuid.'/join')
        ->assertStatus(422)
        ->assertJsonPath('code', 'cohort_not_listed');
});
