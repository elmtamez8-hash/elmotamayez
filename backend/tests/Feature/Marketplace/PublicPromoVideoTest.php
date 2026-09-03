<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;

/*
| Spec 018 · US1 · T007–T008 — the promo video on the public course page.
|
| Every request here is made with NO authentication, because that is the whole
| story: a visitor who has not signed up watches before she pays.
|
| ⚠️ AND `publiclyListed()` IS THE ONLY TENANT GUARD ON THIS PATH.
| `WorkspaceScope` adds no condition without an authenticated user, so the
| last test is not a formality — it proves 018 added no second door beside the
| guard that 001 and 023 already answer through.
*/

/**
 * A published course by an approved, publicly listed teacher.
 *
 * @param  array<string, mixed>  $attrs
 * @return array{0: Course, 1: Workspace}
 */
function promoCourseFixture(array $attrs = []): array
{
    $workspace = marketplaceWorkspace('Academy');
    $teacher = marketplaceTeacher($workspace);

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()
        ->published()
        ->create(array_merge([
            'workspace_id' => $workspace->getKey(),
            'title' => 'أساسيّات التفاضل',
            'created_by' => $teacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ], $attrs)));

    return [$course, $workspace];
}

it('publishes the video id of an approved promo video to an anonymous visitor', function (): void {
    [$course] = promoCourseFixture([
        'promo_video_id' => 'dQw4w9WgXcQ',
        'promo_video_status' => Course::PROMO_APPROVED,
    ]);

    $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('data.promo_video_id', 'dQw4w9WgXcQ');
});

/*
| Every state that is not `approved` reads as «no video», and they are
| indistinguishable from each other on purpose: telling a visitor that something
| is hidden pending approval is telling her it exists.
*/
it('publishes null for every promo state that is not approved', function (string $status): void {
    [$course] = promoCourseFixture([
        'promo_video_id' => 'dQw4w9WgXcQ',
        'promo_video_status' => $status,
    ]);

    $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('data.promo_video_id', null);
})->with([
    Course::PROMO_NONE,
    Course::PROMO_PENDING,
    Course::PROMO_REJECTED,
]);

/*
| ⚠️ THE SECOND HALF OF `hasApprovedPromoVideo()`, AND THE ONE THAT FALLS OUT
| FIRST when somebody «simplifies» the predicate to the status alone.
|
| An approved status over a cleared id is reachable — the offboarding listener
| and a teacher clearing their link both write the id — and a resource that
| trusted the status would hand the page an embed address built from `null`.
*/
it('publishes null when the status says approved but the id is gone', function (): void {
    [$course] = promoCourseFixture([
        'promo_video_id' => null,
        'promo_video_status' => Course::PROMO_APPROVED,
    ]);

    $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('data.promo_video_id', null);
});

/*
| T008 — the guard is `publiclyListed()`, and 018 added nothing beside it.
|
| An approved video must not make an unpublishable course reachable. If any of
| these answered 200, this phase would have opened a second door into the tenant
| data that the public path has no scope to protect.
*/
it('keeps refusing an unpublishable course that happens to hold an approved video', function (): void {
    $withVideo = [
        'promo_video_id' => 'dQw4w9WgXcQ',
        'promo_video_status' => Course::PROMO_APPROVED,
    ];

    [$draft] = promoCourseFixture($withVideo + ['status' => 'draft']);
    $this->getJson("/api/v1/marketplace/courses/{$draft->uuid}")->assertNotFound();

    // The author's approval is the other half of `publicListingConstraints()`.
    $workspace = marketplaceWorkspace('Pending Academy');
    $pendingTeacher = marketplaceTeacher($workspace, [
        'approval_status' => TeacherProfile::STATUS_PENDING,
        'is_publicly_listed' => false,
    ]);

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()
        ->published()
        ->create(array_merge([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $pendingTeacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ], $withVideo)));

    $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")->assertNotFound();
});
