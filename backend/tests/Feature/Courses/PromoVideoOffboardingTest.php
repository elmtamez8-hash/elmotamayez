<?php

declare(strict_types=1);

use App\Modules\Compliance\Events\TeacherOffboardingCompleted;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;

/*
| Spec 018 · T029 — FR-012, through the event rather than across the boundary.
|
| ⚠️ TWO WORKSPACES, NOT ONE. `ExecuteTeacherOffboarding` once had every one of
| its statements workspace-scoped, and a single-workspace fixture hid the whole
| defect: the scope ANDed the right id by accident. A test of anything that
| crosses workspaces proves nothing on one.
|
| The event is dispatched directly rather than driving the whole offboarding
| action: what is under test is that Courses reacts, and the action's own
| preconditions are 013's business and already covered there.
*/

/** A published course with an approved promo video, owned by one teacher. */
function offboardingCourse(Workspace $workspace, int $teacherUserId): Course
{
    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $teacherUserId): Course {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacherUserId,
            'course_type' => Course::TYPE_GROUP,
        ]);

        $course->forceFill([
            'promo_video_id' => 'dQw4w9WgXcQ',
            'promo_video_status' => Course::PROMO_APPROVED,
            'promo_video_reviewed_at' => now(),
        ])->save();

        return $course;
    });
}

it('clears the departing teacher\'s promo videos and leaves everyone else alone', function (): void {
    $leaving = marketplaceWorkspace('Leaving Academy');
    $leavingTeacher = marketplaceTeacher($leaving);
    $leavingCourse = offboardingCourse($leaving, (int) $leavingTeacher->user_id);

    $staying = marketplaceWorkspace('Staying Academy');
    $stayingTeacher = marketplaceTeacher($staying);
    $stayingCourse = offboardingCourse($staying, (int) $stayingTeacher->user_id);

    $offboarding = TeacherOffboarding::query()->create([
        'workspace_id' => $leaving->getKey(),
        'teacher_user_id' => $leavingTeacher->user_id,
        'notice_ends_at' => now()->subDay(),
    ]);

    event(new TeacherOffboardingCompleted($offboarding));

    $leavingCourse->refresh();
    expect($leavingCourse->promo_video_id)->toBeNull()
        ->and($leavingCourse->promo_video_status)->toBe(Course::PROMO_NONE)
        ->and($leavingCourse->promo_video_reviewed_at)->toBeNull();

    // ⚠️ The other workspace is the assertion that a single-workspace fixture
    // cannot make: an unscoped update with no workspace predicate would clear
    // this one too, and every other teacher's video on the platform with it.
    $stayingCourse->refresh();
    expect($stayingCourse->promo_video_id)->toBe('dQw4w9WgXcQ')
        ->and($stayingCourse->promo_video_status)->toBe(Course::PROMO_APPROVED);
});

/*
| WHY THE LISTENER EARNS ITS PLACE AT ALL.
|
| `Marketplace\Listeners\UnlistDepartedTeacher` already hides the departing
| teacher's courses from the public path, so clearing the id changes nothing a
| visitor can see TODAY. What it changes is what happens if the teacher is ever
| relisted: without it, an approval granted before they left comes back with
| them, unreviewed, over a video nobody has looked at since.
*/
it('leaves nothing an approval could come back from', function (): void {
    $workspace = marketplaceWorkspace('Leaving Academy');
    $teacher = marketplaceTeacher($workspace);
    $course = offboardingCourse($workspace, (int) $teacher->user_id);

    $offboarding = TeacherOffboarding::query()->create([
        'workspace_id' => $workspace->getKey(),
        'teacher_user_id' => $teacher->user_id,
        'notice_ends_at' => now()->subDay(),
    ]);

    event(new TeacherOffboardingCompleted($offboarding));

    // All four columns, not just the id: a surviving `approved` over a cleared
    // id is the exact state `hasApprovedPromoVideo()`'s second condition exists
    // to catch, and leaving one behind would mean a relisted teacher's old
    // approval standing over a video nobody has looked at since.
    $row = $course->fresh();

    expect($row->promo_video_id)->toBeNull()
        ->and($row->promo_video_status)->toBe(Course::PROMO_NONE)
        ->and($row->promo_video_reviewed_at)->toBeNull()
        ->and($row->promo_video_reviewed_by)->toBeNull()
        ->and($row->hasApprovedPromoVideo())->toBeFalse();
});
