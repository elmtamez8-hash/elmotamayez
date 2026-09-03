<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| Spec 018 · US2 · T022–T025 — pasting a link, and the review that gates it.
|
| ⚠️ THE PERMISSION IS TESTED IN BOTH DIRECTIONS. A deny-only test passes just
| as well against a permission that NOTHING READS — `taxonomy.manage` shipped in
| 009 declared, seeded, named in a migration docblock and read by no file at
| all, and every assertion about it was true. The allow direction is what
| catches that, because a missing policy registration fails OPEN into «no policy
| applies».
*/

/**
 * A published course by an approved, publicly listed teacher, plus its owner.
 *
 * @return array{0: Course, 1: Workspace, 2: TeacherProfile}
 */
function promoTeacherCourse(string $name = 'Academy'): array
{
    $workspace = marketplaceWorkspace($name);
    $teacher = marketplaceTeacher($workspace);

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()
        ->published()
        ->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]));

    return [$course, $workspace, $teacher];
}

it('stores the extracted id and never the pasted url', function (): void {
    [$course, $workspace] = promoTeacherCourse();

    $owner = $this->addWorkspaceMember($workspace, Roles::TEACHER);
    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}", [
        'promo_video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PL123',
    ])->assertOk();

    $course->refresh();

    // The id ALONE. A stored url is a raw string one line from an iframe src.
    expect($course->promo_video_id)->toBe('dQw4w9WgXcQ')
        ->and($course->promo_video_status)->toBe(Course::PROMO_PENDING);
});

it('refuses a link it does not accept and writes nothing', function (): void {
    [$course, $workspace] = promoTeacherCourse();

    $owner = $this->addWorkspaceMember($workspace, Roles::TEACHER);
    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}", [
        'promo_video_url' => 'https://vimeo.com/123456789',
    ])->assertStatus(422)->assertJsonValidationErrors('promo_video_url');

    expect($course->refresh()->promo_video_id)->toBeNull()
        ->and($course->promo_video_status)->toBe(Course::PROMO_NONE);
});

/*
| The status is deliberately outside `$fillable`, so it cannot be moved from a
| payload — a second door onto the approval decision from outside the action
| that owns it (the `captured_order_id` rule).
*/
it('ignores a status sent in the update payload', function (): void {
    [$course, $workspace] = promoTeacherCourse();

    $owner = $this->addWorkspaceMember($workspace, Roles::TEACHER);
    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}", [
        'promo_video_url' => 'https://youtu.be/dQw4w9WgXcQ',
        'promo_video_status' => Course::PROMO_APPROVED,
    ])->assertOk();

    expect($course->refresh()->promo_video_status)->toBe(Course::PROMO_PENDING);
});

/*
| ⚠️ T024 — THE SWAP. Without this, an approval survives a changed link: paste
| something acceptable, get approved, then paste anything at all. The review
| becomes a name with nothing behind it, and nothing anywhere reports it.
*/
it('drops an existing approval the moment a different link is pasted', function (): void {
    [$course, $workspace] = promoTeacherCourse();

    $course->forceFill([
        'promo_video_id' => 'dQw4w9WgXcQ',
        'promo_video_status' => Course::PROMO_APPROVED,
        'promo_video_reviewed_at' => now(),
    ])->save();

    // It is public right now.
    $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('data.promo_video_id', 'dQw4w9WgXcQ');

    $owner = $this->addWorkspaceMember($workspace, Roles::TEACHER);
    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}", [
        'promo_video_url' => 'https://youtu.be/aaaaaaaaaaa',
    ])->assertOk();

    expect($course->refresh()->promo_video_status)->toBe(Course::PROMO_PENDING)
        ->and($course->promo_video_reviewed_at)->toBeNull();

    // And it is gone from the public page immediately, not at the next review.
    test()->asGuest();
    $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('data.promo_video_id', null);
});

it('refuses a video from a teacher who is not publicly listed', function (): void {
    $workspace = marketplaceWorkspace('Pending Academy');
    $teacher = marketplaceTeacher($workspace, [
        'approval_status' => TeacherProfile::STATUS_PENDING,
        'is_publicly_listed' => false,
    ]);

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()
        ->published()
        ->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]));

    $owner = $this->addWorkspaceMember($workspace, Roles::TEACHER);
    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}", [
        'promo_video_url' => 'https://youtu.be/dQw4w9WgXcQ',
    ])->assertStatus(422);

    expect($course->refresh()->promo_video_id)->toBeNull();
});

it('clears the video and its review when the link is removed', function (): void {
    [$course, $workspace] = promoTeacherCourse();

    $course->forceFill([
        'promo_video_id' => 'dQw4w9WgXcQ',
        'promo_video_status' => Course::PROMO_APPROVED,
        'promo_video_reviewed_at' => now(),
    ])->save();

    $owner = $this->addWorkspaceMember($workspace, Roles::TEACHER);
    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}", ['promo_video_url' => null])->assertOk();

    expect($course->refresh()->promo_video_id)->toBeNull()
        ->and($course->promo_video_status)->toBe(Course::PROMO_NONE)
        ->and($course->promo_video_reviewed_at)->toBeNull();
});

/*
| ⚠️ A REFUSED VIDEO MUST NOT COMMIT THE REST OF THE REQUEST.
|
| `SetCoursePromoVideo` throws for a teacher who is not publicly listed, and the
| check cannot move above `$course->update()` because the promo write has to
| follow it. Unwrapped, the title in the same request was already saved while the
| response said 422 — the client is told nothing happened, and something did.
|
| Found in review of this branch, not by the tests above: every one of them
| asserted the promo columns and none looked at the fields beside them.
*/
it('rolls the whole update back when the video is refused', function (): void {
    $workspace = marketplaceWorkspace('Pending Academy');
    $teacher = marketplaceTeacher($workspace, [
        'approval_status' => TeacherProfile::STATUS_PENDING,
        'is_publicly_listed' => false,
    ]);

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()
        ->published()
        ->create([
            'workspace_id' => $workspace->getKey(),
            'title' => 'العنوان الأصلي',
            'created_by' => $teacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]));

    $owner = $this->addWorkspaceMember($workspace, Roles::TEACHER);
    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}", [
        'title' => 'عنوانٌ جديد',
        'promo_video_url' => 'https://youtu.be/dQw4w9WgXcQ',
    ])->assertStatus(422);

    // The title travelled in the SAME request and must not have survived it.
    expect($course->refresh()->title)->toBe('العنوان الأصلي')
        ->and($course->promo_video_id)->toBeNull();
});
