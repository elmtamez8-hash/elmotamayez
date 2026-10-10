<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MediaFixtures;

uses(MediaFixtures::class);

/*
| Security scan 2026-10-10 — F22 · F17.
*/

it('stops renewing a grant once the enrolment behind it has ended', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);
    [$student] = $this->enrolledViewer($workspace, $lesson);

    $grant = $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertOk()->json('grant');
    $this->postJson("/api/v1/playback/{$grant}/renew")->assertOk();

    Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', $student->getKey())
        ->update(['status' => 'cancelled']);

    // Refused, whichever of the two refusal shapes the playback door gives it.
    expect($this->postJson("/api/v1/playback/{$grant}/renew")->status())->toBeIn([403, 422]);
});

it('refuses a session of a course the student is not enrolled in', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $teacher = TeacherProfile::factory()->create(['workspace_id' => $workspace->getKey(), 'user_id' => $owner->getKey()]);
    $free = Course::factory()->published()->create(['workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey()]);
    $other = Course::factory()->published()->create(['workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey()]);

    $session = app(WorkspaceContext::class)->forWorkspace($workspace, fn () => ClassSession::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $other->getKey(),
    ]));

    $student = User::factory()->create();
    $this->createEnrollment($workspace, $free, $student);

    Sanctum::actingAs($student);
    $this->asGuest();

    $this->getJson("/api/v1/class-sessions/{$session->uuid}")->assertForbidden();
});
