<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| Spec 018 · T022–T023 — the review, in BOTH directions.
|
| ⚠️ THE ALLOW DIRECTION IS THE ONE THAT CATCHES A PERMISSION NOBODY READS.
| A deny-only file passes perfectly against a constant that is declared, seeded,
| named in a docblock and read by no code at all — `taxonomy.manage` shipped
| exactly that way in 009, and its tenant-side test was true too, because the
| answer was «nobody asks».
|
| ⚠️ AND A PLATFORM-PERMISSION TEST NEEDS TWO WORKSPACES. `WorkspaceContext::id()`
| falls back to `users.last_workspace_id` for platform staff as well, so a
| single-workspace fixture cannot tell a correctly unscoped read from a scoped
| one — the audit chain shipped that bug and answered «nothing was bought» with
| a 200.
*/

/*
| The platform roles are real spatie rows with `team_id = null`, and
| `PlatformStaffDirectory` reads their attached permissions — so the seeder is
| what makes `compliance-officer` mean anything. Without it every request here
| is a 403 for the wrong reason, and the file would read as a passing deny test.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** A published course with a pending promo video, in its own workspace. */
function reviewableCourse(string $name): Course
{
    $workspace = marketplaceWorkspace($name);
    $teacher = marketplaceTeacher($workspace);

    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $teacher): Course {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]);

        $course->forceFill([
            'promo_video_id' => 'dQw4w9WgXcQ',
            'promo_video_status' => Course::PROMO_PENDING,
        ])->save();

        return $course;
    });
}

it('lets the permission holder approve, and the video reaches the public page', function (): void {
    // Two workspaces: the reviewer's fallback is NOT the one owning the course.
    $reviewerHome = marketplaceWorkspace('Reviewer Home');
    $course = reviewableCourse('Teaching Academy');

    $officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER);
    $officer->forceFill(['last_workspace_id' => $reviewerHome->getKey()])->save();

    Sanctum::actingAs($officer);

    $this->postJson("/api/v1/courses/{$course->uuid}/promo-video/review", [
        'decision' => Course::PROMO_APPROVED,
    ])->assertOk();

    expect($course->refresh()->promo_video_status)->toBe(Course::PROMO_APPROVED)
        ->and($course->promo_video_reviewed_by)->toBe($officer->getKey());

    /*
    | ⚠️ THE GUARD IS FORGOTTEN, NOT JUST THE CONTEXT. `asGuest()` replaces the
    | WorkspaceContext singleton and leaves `Sanctum::actingAs()` in place — so
    | the «public» read below would still be made AS THE OFFICER, whose context
    | resolves to their own workspace and whose WorkspaceScope then hides a
    | course belonging to another one. A 404 that says nothing about this phase.
    */
    auth()->forgetGuards();
    test()->asGuest();

    $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('data.promo_video_id', 'dQw4w9WgXcQ');
});

/*
| ⚠️ THE WHOLE POINT OF THE PERMISSION. A workspace owner who could approve
| their own video makes the review a name with nothing behind it — and the
| owner is the person most likely to try.
*/
it('refuses the workspace owner reviewing their own course', function (): void {
    $workspace = marketplaceWorkspace('Teaching Academy');
    $teacher = marketplaceTeacher($workspace);

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $teacher): Course {
        $c = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]);
        $c->forceFill([
            'promo_video_id' => 'dQw4w9WgXcQ',
            'promo_video_status' => Course::PROMO_PENDING,
        ])->save();

        return $c;
    });

    $owner = $this->addWorkspaceMember($workspace, Roles::TENANT_OWNER);
    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/courses/{$course->uuid}/promo-video/review", [
        'decision' => Course::PROMO_APPROVED,
    ])->assertForbidden();

    expect($course->refresh()->promo_video_status)->toBe(Course::PROMO_PENDING);
});

it('refuses a rejection that carries no reason', function (): void {
    $course = reviewableCourse('Teaching Academy');

    Sanctum::actingAs(makePlatformStaff(Roles::COMPLIANCE_OFFICER));

    $this->postJson("/api/v1/courses/{$course->uuid}/promo-video/review", [
        'decision' => Course::PROMO_REJECTED,
    ])->assertStatus(422);

    expect($course->refresh()->promo_video_status)->toBe(Course::PROMO_PENDING);
});

it('records a rejection with its reason, and the page stays empty', function (): void {
    $course = reviewableCourse('Teaching Academy');

    Sanctum::actingAs(makePlatformStaff(Roles::COMPLIANCE_OFFICER));

    $this->postJson("/api/v1/courses/{$course->uuid}/promo-video/review", [
        'decision' => Course::PROMO_REJECTED,
        'reason' => 'الصوت غير واضح.',
    ])->assertOk();

    expect($course->refresh()->promo_video_status)->toBe(Course::PROMO_REJECTED);

    auth()->forgetGuards();
    test()->asGuest();

    $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('data.promo_video_id', null);
});

it('refuses to review a course with nothing submitted', function (): void {
    $workspace = marketplaceWorkspace('Teaching Academy');
    $teacher = marketplaceTeacher($workspace);

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()
        ->published()
        ->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]));

    Sanctum::actingAs(makePlatformStaff(Roles::COMPLIANCE_OFFICER));

    $this->postJson("/api/v1/courses/{$course->uuid}/promo-video/review", [
        'decision' => Course::PROMO_APPROVED,
    ])->assertStatus(422);
});
