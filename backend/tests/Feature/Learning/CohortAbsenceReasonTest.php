<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Shared\Support\WorkspaceContext;

/*
| ٠٣٦ · T112 · FR-014 — the teacher is told WHY their group is not listed, and
| the student never is.
|
| ⛔ WITHOUT THE SENTENCE THE TEACHER READS THE ABSENCE AS A MISTAKE OF THEIRS
| and goes hunting for a setting that was never the problem. One of the three
| answers — «باقتها بانتظار التسعير» — names a step that is not theirs to take at
| all, and it is the only one of the three with no remedy attached.
|
| ⛔ AND WHICH of the three came back is the assertion, never «did something come
| back». A screen printing «لا توجد باقة» over a plan that is merely switched off
| sends the teacher to write a second plan they already have.
|
| ⚠️ THE LEAK IS TESTED IN THE SAME FILE, because the trap is a SHARED
| TRANSFORMER: one `CohortResource` feeds the student's picker and both of the
| teacher's screens, so a field added there reaches the student too. The reason is
| assembled in the teacher's controller, and that is measured from the student's
| side rather than asserted in prose.
*/
beforeEach(function (): void {
    /*
    | ⚠️ TWO PEOPLE, AND BOTH ARE NEEDED. The workspace OWNER is who holds the
    | roles the teacher's screens ask for — a published `TeacherProfile` carries
    | no permissions at all, and reading the group page as one answers 403, which
    | looks exactly like the field being absent. The published profile is what
    | makes the public course page resolve, and it builds its own user because
    | `is_publicly_listed` is derived rather than assigned.
    */
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->workspace->forceFill(['participates_in_marketplace' => true])->save();
    $this->teacher->forceFill(['last_workspace_id' => $this->workspace->getKey()])->save();

    $this->teacherProfile = marketplaceTeacher($this->workspace);

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->teacherProfile->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]),
    );

    $this->cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->getKey(),
        'name' => 'مجموعة السبت',
    ]);
});

/** What the teacher's own group page says about this group's absence. */
function absenceOnTeacherPage(): ?array
{
    return test()->actingAs(test()->teacher, 'sanctum')
        ->getJson('/api/v1/manage/cohorts/'.test()->cohort->uuid)
        ->assertOk()
        ->json('absence_reason');
}

it('tells the teacher no plan covers the group at all', function (): void {
    expect(absenceOnTeacherPage())
        ->toMatchArray(['code' => 'no_plan'])
        // A remedy, because writing a plan is an act of theirs.
        ->and(absenceOnTeacherPage()['remedy'])->not->toBeNull();
});

it('tells the teacher the platform has not priced their plan yet', function (): void {
    Plan::factory()->forCohort((string) $this->cohort->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => null,
    ]);

    $reason = absenceOnTeacherPage();

    expect($reason['code'])->toBe('awaiting_pricing')
        /*
        | ⛔ NO REMEDY, AND THIS IS THE ONE CASE THE WHOLE FIELD EXISTS FOR.
        | Offering an action here would send a teacher to do something that
        | cannot help — the next move is the platform's.
        */
        ->and($reason['remedy'])->toBeNull();
});

it('tells the teacher their plan is switched off', function (): void {
    Plan::factory()->forCohort((string) $this->cohort->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
        'is_active' => false,
    ]);

    expect(absenceOnTeacherPage())->toMatchArray(['code' => 'disabled'])
        ->and(absenceOnTeacherPage()['remedy'])->not->toBeNull();
});

it('says nothing at all about a group that IS listed', function (): void {
    // ⚠️ THE ABSENCE OF THE KEY, not a fourth «nothing is wrong» value. A case
    // meaning «no gap» is one every caller has to remember to exclude.
    Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'coverage_type' => PlanCoverage::Workspace,
        'coverage_uuid' => null,
    ]);

    expect(absenceOnTeacherPage())->toBeNull();
});

it('never lets the reason reach a student or a visitor', function (): void {
    /*
    | ⛔ MEASURED FROM BOTH OF THE STUDENT'S DOORS, in the state where a reason
    | exists and is therefore available to leak. The picker DROPS the group — so
    | the proof there is that no row carries the key at all — and the public page
    | does the same.
    */
    Plan::factory()->forCohort((string) $this->cohort->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => null,
    ]);

    // A second group with a real price, so both payloads are non-empty and the
    // assertion is about a missing FIELD rather than a missing list.
    $listed = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->getKey(),
        'name' => 'مجموعة الأحد',
    ]);

    Plan::factory()->forCohort((string) $listed->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    $student = User::factory()->create(['last_workspace_id' => null]);

    Enrollment::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $student->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);

    $picker = $this->actingAs($student, 'sanctum')
        ->getJson('/api/v1/courses/'.$this->course->uuid.'/cohorts')
        ->assertOk()
        ->json('cohorts');

    expect($picker)->toHaveCount(1);

    foreach ($picker as $row) {
        expect($row)->not->toHaveKey('absence_reason');
    }

    $this->asGuest();

    $public = $this->getJson('/api/v1/marketplace/courses/'.$this->course->uuid)
        ->assertOk()
        ->json('data.cohorts');

    expect($public)->toHaveCount(1);

    foreach ($public as $row) {
        expect($row)->not->toHaveKey('absence_reason');
    }
});
