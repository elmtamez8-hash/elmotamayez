<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\WorkspaceContext;
use Illuminate\Testing\TestResponse;

/*
| Spec 027 · FR-004 · SC-008 — the door that gave any account any course, free.
|
| ⚠️ WHAT THIS MEASURED BEFORE THE FIX: register an account, take a course uuid
| off the public marketplace, `POST /courses/{uuid}/enroll`, and the curriculum,
| the lessons and every playback grant open — no order, no receipt, no teacher.
| `CoursePolicy::view()` allows every published course and
| `belongsToCurrentWorkspace()` raises no objection on a null context, which is
| every student. It shipped with zero callers under `frontend/src`, so nothing
| exercised it and nothing noticed.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // ⚠️ `last_workspace_id` NULL and the context singleton reset: nothing on a
    // student's path writes that column in production, and a fixture that stamps
    // it measures a person who does not exist.
    $this->student = User::factory()->create(['last_workspace_id' => null]);
    app()->forgetInstance(WorkspaceContext::class);
});

/**
 * Published and with NO one-off price.
 *
 * ⚠️ `courseWithRate()` prices the course, so the zero has to be written here on
 * purpose — that is the whole point of these cases: a course whose one-off price
 * is 0 is what `isFree()` calls free, and what a plan can nonetheless be selling.
 */
function publishAtPrice(Course $course, int $priceMinor = 0): Course
{
    $course->forceFill(['status' => 'published', 'price_minor' => $priceMinor])->save();

    return $course->refresh();
}

/*
 * ⚠️ BOTH HELPERS CARRY A NAME NOBODY ELSE WOULD PICK. Pest files share one
 * global function namespace, so `publish()` and `enroll()` would collide with
 * the first other file that wants them — as a fatal redeclaration, invisible to
 * a single-file run.
 */
function postSelfEnrollment(User $student, Course $course): TestResponse
{
    return test()->actingAs($student, 'sanctum')
        ->postJson('/api/v1/courses/'.$course->uuid.'/enroll');
}

it('refuses a course sold by a plan even though its one-off price is zero', function (): void {
    /*
    | ⚠️ THIS IS THE CASE `Course::isFree()` CANNOT SEE, and the reason FR-004 is
    | not implemented with it. `courses.price` is `->default(0)` and prices the
    | ONE-OFF purchase alone, so a course sold by subscription reads as free —
    | narrowing the door with `isFree()` leaves it open for exactly the courses
    | spec 027 exists to sell, and SC-008 passes green over the top of it.
    */
    $course = publishAtPrice(courseWithRate((int) $this->workspace->getKey()));
    expect($course->isFree())->toBeTrue();

    Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 45_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $course->uuid,
    ]);

    $response = postSelfEnrollment($this->student, $course);

    $response->assertStatus(422)->assertJsonPath('code', 'purchase_required');
    expect(Enrollment::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a course reached by a workspace-wide plan', function (): void {
    $course = publishAtPrice(courseWithRate((int) $this->workspace->getKey()));

    Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 45_000,
        'coverage_type' => PlanCoverage::Workspace,
        'coverage_uuid' => null,
    ]);

    postSelfEnrollment($this->student, $course)->assertStatus(422);
});

it('refuses a course carrying a one-off price', function (): void {
    $course = publishAtPrice(courseWithRate((int) $this->workspace->getKey()), 25_000);

    postSelfEnrollment($this->student, $course)->assertStatus(422);
});

it('still lets anyone enrol in a genuinely free course', function (): void {
    // The route is narrowed, not deleted: a free course is a real case and it is
    // the only one that still passes.
    $course = publishAtPrice(courseWithRate((int) $this->workspace->getKey()));

    postSelfEnrollment($this->student, $course)->assertCreated();

    expect(Enrollment::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('is not fooled by an unpriced or switched-off plan', function (): void {
    // A plan awaiting a price sells nothing, so the course is still free today.
    $course = publishAtPrice(courseWithRate((int) $this->workspace->getKey()));

    Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => null,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $course->uuid,
    ]);

    Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 45_000,
        'is_active' => false,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $course->uuid,
    ]);

    postSelfEnrollment($this->student, $course)->assertCreated();
});

it('does not let another teacher’s plan close this teacher’s free course', function (): void {
    /*
    | ⚠️ TWO WORKSPACES, BECAUSE THE PREDICATE IS A CROSS-TENANT READ. It declares
    | `withoutWorkspaceScope()` — necessary, since it is asked on a student's path
    | where the scope adds nothing anyway — so a grouping mistake in the coverage
    | clause would publish every priced plan on the platform as covering this
    | course, and a one-workspace fixture could never see it.
    */
    $course = publishAtPrice(courseWithRate((int) $this->workspace->getKey()));

    [$otherWorkspace] = $this->createWorkspaceWithOwner();

    Plan::factory()->create([
        'workspace_id' => $otherWorkspace->getKey(),
        'price_minor' => 45_000,
        'coverage_type' => PlanCoverage::Workspace,
        'coverage_uuid' => null,
    ]);

    postSelfEnrollment($this->student, $course)->assertCreated();
});
