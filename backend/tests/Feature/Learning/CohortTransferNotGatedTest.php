<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Models\Plan;
use App\Shared\Support\WorkspaceContext;

/*
| ٠٣٦ · T039 · FR-019 — a TRANSFER is not a sale, and the price gate does not
| reach it.
|
| ⛔ THE STUDENT HAS ALREADY PAID FOR THIS COURSE. Moving between its groups is a
| change of timetable, not a purchase — refusing it because the destination's
| plan is unpriced would trap somebody in a Saturday class over a commercial
| detail between their teacher and the platform.
|
| ⛔ AND THE HALF THAT BITES IS THE WRITE, NOT THE REQUEST. `RequestTransfer`
| reads the destination's STATUS alone, so it passes on every build there has
| ever been — a case that stops at «the request was accepted» is green whether or
| not a guard was wrongly added to the writer. This walks all the way to the
| membership row in the destination, which is where such a guard would fire.
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

    $make = fn (string $name): Cohort => Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->getKey(),
        'name' => $name,
    ]);

    $this->from = $make('مجموعة السبت');
    $this->to = $make('مجموعة الأحد');

    /*
    | ⛔ THE PLAN NAMES THE GROUP THEY ARE LEAVING, AND ONLY IT. A course-wide
    | plan would reach both and the destination would be perfectly listed — at
    | which point the case proves nothing about an exemption.
    */
    Plan::factory()->forCohort((string) $this->from->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
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

    app(JoinCohort::class)->handle($this->from, $this->student);
});

it('moves a student into a group no price reaches', function (): void {
    $request = $this->actingAs($this->student, 'sanctum')
        ->postJson('/api/v1/cohorts/'.$this->to->uuid.'/transfer-requests', [
            'reason' => 'الأحد أنسب لي',
        ])
        ->assertStatus(201)
        ->json();

    /*
    | ⚠️ THE CONTEXT IS FORGOTTEN BETWEEN THE TWO ACTORS. `WorkspaceContext` is an
    | application-wide singleton that CACHES its resolution, and the student's
    | request above resolved it to null — a student is a member of no workspace.
    | Left as it is, the teacher's own policy check reads that cached null,
    | `belongsToCurrentWorkspace()` denies, and the case fails 403 for a reason
    | that has nothing to do with the price.
    */
    app()->forgetInstance(WorkspaceContext::class);

    $this->actingAs($this->teacher, 'sanctum')
        ->postJson('/api/v1/manage/transfer-requests/'.$request['uuid'].'/approve')
        ->assertOk();

    $membership = CohortMembership::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())
        ->whereNull('closed_at')
        ->first();

    // ⛔ THE ROW IN THE DESTINATION. A 200 with the student still in their old
    // group is the failure this assertion exists for.
    expect($membership)->not->toBeNull()
        ->and((int) $membership->cohort_id)->toBe((int) $this->to->getKey());
});

it('still refuses that destination to somebody joining it fresh', function (): void {
    /*
    | ⚠️ THE OTHER HALF. Without it «the transfer worked» is equally true of a
    | build with no gate at all — the exemption is about WHO is asking and what
    | they already hold.
    */
    $newcomer = User::factory()->create(['last_workspace_id' => null]);

    Enrollment::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $newcomer->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);

    $this->actingAs($newcomer, 'sanctum')
        ->postJson('/api/v1/cohorts/'.$this->to->uuid.'/join')
        ->assertStatus(422)
        ->assertJsonPath('code', 'cohort_not_listed');
});
