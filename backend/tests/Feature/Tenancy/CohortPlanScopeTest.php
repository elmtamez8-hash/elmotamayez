<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Shared\Support\WorkspaceContext;

/*
| ٠٣٦ · T034 · T035 — the price gate reads `plans`, and `plans` is tenant-scoped.
|
| ⛔ A GUEST CANNOT SEE EITHER DEFECT, AND THAT IS THE POINT OF THIS FILE.
| `WorkspaceScope` adds no condition at all when the context is null, which it
| always is for a visitor — so a guest reads correctly whether or not the bypass
| is there, and a guest-only test is green against a build with none. The victim
| is a SIGNED-IN TEACHER FROM ANOTHER WORKSPACE browsing the marketplace: their
| context resolves, the scope bites, every plan of the course they are looking at
| is invisible, and every group on the page disappears behind a 200.
|
| ⛔ AND THE MIRROR IS THE SAME LINE READ THE OTHER WAY. A workspace-wide plan
| carries a NULL coverage uuid, so an unpinned inheritance arm asks «is there ANY
| live workspace plan on the platform?» — true for every group of every teacher.
| One workspace in a fixture cannot see that in any combination.
|
| ⚠️ THE DIVERGENCE PROOF, written down rather than assumed: delete
| `withoutWorkspaceScope()` from `PlanReach::covering()` and re-run — the guest
| case stays GREEN and the foreign-teacher case turns RED. That asymmetry is what
| this file exists to record.
*/

/** @return array{0: Course, 1: Cohort, 2: mixed} */
function pricedCourseInOwnWorkspace(): array
{
    $workspace = marketplaceWorkspace('أكاديمية أ');
    $teacher = marketplaceTeacher($workspace);

    $course = app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]),
    );

    $cohort = Cohort::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'created_by' => $teacher->user_id,
        'name' => 'مجموعة السبت',
    ]);

    return [$course, $cohort, $workspace];
}

/** @return list<string> the group names the public page publishes */
function publishedGroupNames(Course $course): array
{
    return array_column(
        test()->getJson('/api/v1/marketplace/courses/'.$course->uuid)->assertOk()->json('data.cohorts'),
        'name',
    );
}

it('publishes a priced group to a GUEST', function (): void {
    [$course] = pricedCourseInOwnWorkspace();
    groupPriceFor($course);

    $this->asGuest();

    // Correct with the bypass and correct without it: the scope is inert for a
    // reader who has no workspace. This is the control, not the guard.
    expect(publishedGroupNames($course))->toBe(['مجموعة السبت']);
});

it('publishes the same group to a signed-in teacher from ANOTHER workspace', function (): void {
    [$course] = pricedCourseInOwnWorkspace();
    groupPriceFor($course);

    // Three explicit lines, in order: a second workspace, a real sign-in, and
    // that reader's own workspace pinned. Skip the third and they read with a
    // null context — i.e. as the guest above, which proves nothing.
    [$foreignWorkspace, $foreignTeacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية ب']);
    $foreignTeacher->forceFill(['last_workspace_id' => $foreignWorkspace->getKey()])->save();

    $this->actingAs($foreignTeacher, 'sanctum');

    expect(publishedGroupNames($course))->toBe(['مجموعة السبت']);
});

it('does not let another teacher blanket plan publish this group', function (): void {
    [$course] = pricedCourseInOwnWorkspace();

    /*
    | ⛔ WORKSPACE COVERAGE IS A CONDITION OF THE FIXTURE, NOT A DETAIL. A plan
    | naming a COURSE in workspace ب can never match a group in أ whatever the
    | pin does — its uuid simply is not in the candidate list — so the case would
    | pass with the pin deleted. Only the blanket shape, whose coverage uuid is
    | NULL and therefore matches by the absence of a uuid, reaches the arm this
    | measures.
    */
    [$otherWorkspace] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية ب']);

    Plan::factory()->group()->create([
        'workspace_id' => $otherWorkspace->getKey(),
        'coverage_type' => PlanCoverage::Workspace,
        'coverage_uuid' => null,
    ]);

    $this->asGuest();

    expect(publishedGroupNames($course))->toBe([]);
});
