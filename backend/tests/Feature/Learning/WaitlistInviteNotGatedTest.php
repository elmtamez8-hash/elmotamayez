<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\InviteFromWaitlist;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CourseWaitlistEntry;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;

/*
| ٠٣٦ · T040 · FR-020 — inviting from the waiting list is ADMINISTRATION, and the
| price gate does not reach it.
|
| ⛔ IT IS AN ASSIGNMENT, NOT A SALE. The officer decides who is offered the seat
| that just opened; refusing because the platform has not priced a plan would
| leave a queue nobody can be let out of, with the people in it waiting on a
| decision that is not theirs and not the teacher's. `InviteFromWaitlist` asks
| `isAssignable()` — a different question with a different name — and this file
| is what fails if somebody «tidies» the two into one.
|
| ⚠️ THE INVITE ROW IS THE ASSERTION, never the return count alone: a handler
| that answered `1` and wrote nothing is the same outcome wearing a number.
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

    // ⛔ NO PLAN ANYWHERE. This group is exactly the one a student may not join.
    $this->cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->getKey(),
        'capacity' => 4,
        'members_count' => 0,
    ]);

    $this->waiting = User::factory()->create(['last_workspace_id' => null]);

    CourseWaitlistEntry::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->waiting->getKey(),
        'closed_slot' => 0,
    ]);
});

it('invites from the waiting list into a group no price reaches', function (): void {
    $invited = app(InviteFromWaitlist::class)->handle($this->cohort, $this->teacher);

    $entry = CourseWaitlistEntry::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $this->waiting->getKey())
        ->first();

    expect($invited)->toBe(1)
        ->and($entry->invited_at)->not->toBeNull();
});

it('still refuses that group to the student themselves', function (): void {
    /*
    | ⚠️ THE OTHER HALF, IN THE SAME FILE. «The invitation went out» is
    | unremarkable on a build with no gate at all; what makes it an EXEMPTION is
    | that the student's own door is shut on the identical group.
    */
    /*
    | ⚠️ ENROLLED FIRST, OR THE DOOR REFUSES ONE STEP EARLIER WITH 403 «لست
    | مسجَّلاً» — a person on a waiting list holds no enrolment, and a control
    | that stops at the wrong guard measures nothing about the price.
    */
    Enrollment::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->waiting->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);

    $this->actingAs($this->waiting, 'sanctum')
        ->postJson('/api/v1/cohorts/'.$this->cohort->uuid.'/join')
        ->assertStatus(422)
        ->assertJsonPath('code', 'cohort_not_listed');
});
