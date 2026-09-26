<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/*
| «نشرتُ الكورس ولا أحدَ يصلُ إليه» — 2026-09-26.
|
| A course published with `visibility = private`, or in a workspace that is not
| in the marketplace, answers 404 on `/courses/{slug}` and on `/subscribe` — the
| right answer to a visitor, and a silent one to the teacher who has just
| pressed «انشر الكورس». The author's own read now carries WHY, and the switch
| back to draft exists as a route.
*/

/**
 * @param  array<string, mixed>  $course
 * @return array{0: Workspace, 1: User, 2: Course}
 */
function reachableTeacherCourse(array $pair, array $course = [], bool $participates = true, bool $listed = true): array
{
    [$workspace, $owner] = $pair;
    $workspace->forceFill(['participates_in_marketplace' => $participates])->save();

    $profile = TeacherProfile::factory();
    TeacherProfile::query()->withoutWorkspaceScope()->create(
        ($listed ? $profile->published() : $profile)->raw([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $owner->getKey(),
        ]),
    );

    $model = Course::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
        ...$course,
    ]);

    return [$workspace, $owner, $model];
}

it('tells the author a published PRIVATE course is not publicly reachable, and why', function (): void {
    [, $owner, $course] = reachableTeacherCourse($this->createWorkspaceWithOwner(), ['visibility' => 'private']);

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('status', 'published')
        ->assertJsonPath('public_listing.listed', false)
        ->assertJsonPath('public_listing.blockers', ['private']);
});

it('names the workspace and the teacher when THEY are what keeps it off the marketplace', function (): void {
    [, $owner, $course] = reachableTeacherCourse($this->createWorkspaceWithOwner(), participates: false, listed: false);

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('public_listing.blockers', ['workspace_not_in_marketplace', 'teacher_not_listed']);
});

it('answers «listed» exactly when the public page answers 200 — the same predicate', function (): void {
    [, $owner, $course] = reachableTeacherCourse($this->createWorkspaceWithOwner());

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('public_listing.listed', true)
        ->assertJsonPath('public_listing.blockers', []);

    // The public door, for the same row: listed ⇔ reachable.
    $this->getJson("/api/v1/marketplace/courses/{$course->slug}")->assertOk();
    expect($course->fresh()?->isPubliclyListed())->toBeTrue();
});

it('never tells a reader who cannot edit the course why it is hidden', function (): void {
    [, , $course] = reachableTeacherCourse($this->createWorkspaceWithOwner(), ['visibility' => 'private']);

    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson("/api/v1/courses/{$course->uuid}");

    // Whatever the view policy answers, the reasons are never on it.
    expect($response->json())->not->toHaveKey('public_listing');
});

it('takes a published course back to draft through the route the screen calls', function (): void {
    [, $owner, $course] = reachableTeacherCourse($this->createWorkspaceWithOwner());

    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/courses/{$course->uuid}/unpublish")
        ->assertOk()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('public_listing.blockers', ['draft']);

    expect($course->fresh()?->status)->toBe('draft');
});

it('refuses the unpublish to somebody from another workspace', function (): void {
    [, , $course] = reachableTeacherCourse($this->createWorkspaceWithOwner());
    [, $stranger] = $this->createWorkspaceWithOwner();

    Sanctum::actingAs($stranger);

    $this->postJson("/api/v1/courses/{$course->uuid}/unpublish")->assertStatus(404);

    expect($course->fresh()?->status)->toBe('published');
});
