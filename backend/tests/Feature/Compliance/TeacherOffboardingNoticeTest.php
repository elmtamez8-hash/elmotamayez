<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\RequestTeacherOffboarding;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\GuardianPermission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

/*
| `teacher_offboarding_notice` from the teacher's own request to leave
| (spec 013 · FR-033 · `EveryNotificationTypeIsTestedTest`).
|
| MANDATORY and guardian-targeting: the teacher leaving ends a paid arrangement
| the guardian usually made, so the guardian entitled to the SCHEDULE hears it —
| and one entitled only to attendance does not. A student whose enrolment has
| already ended has nothing left to lose and is not told.
*/

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
});

function offboardingNoticeStudent(Course $course, EnrollmentStatus $status): User
{
    // Self-registered: a member of no workspace, with a null context.
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    Enrollment::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->getKey(),
        'student_user_id' => $student->getKey(),
        'source' => 'manual',
        'status' => $status,
        'enrolled_at' => now(),
    ]);

    return $student;
}

it('tells every actively enrolled student, and the guardian who arranged the schedule', function (): void {
    [$workspace, $teacher] = $this->createWorkspaceWithOwner();

    $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);
    $otherCourse = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);

    $active = offboardingNoticeStudent($course, EnrollmentStatus::Active);
    $alsoActive = offboardingNoticeStudent($otherCourse, EnrollmentStatus::Active);
    // A completed enrolment keeps full access (`Enrollment::GRANTING_STATUSES`),
    // so the teacher leaving ends something it still holds.
    $completed = offboardingNoticeStudent($course, EnrollmentStatus::Completed);
    $finished = offboardingNoticeStudent($course, EnrollmentStatus::Cancelled);

    $scheduleGuardian = guardianOf($active, [GuardianPermission::Schedule]);
    $attendanceGuardian = guardianOf($active, [GuardianPermission::Attendance]);

    app(RequestTeacherOffboarding::class)->handle($workspace, $teacher);

    assertNotifiedOnce($active, NotificationType::TeacherOffboardingNotice);
    assertNotifiedOnce($alsoActive, NotificationType::TeacherOffboardingNotice);
    assertNotifiedOnce($completed, NotificationType::TeacherOffboardingNotice);
    assertNotifiedOnce($scheduleGuardian, NotificationType::TeacherOffboardingNotice);

    expect(wasNotified($finished, NotificationType::TeacherOffboardingNotice))->toBeFalse()
        ->and(wasNotified($attendanceGuardian, NotificationType::TeacherOffboardingNotice))->toBeFalse()
        ->and(wasNotified($teacher, NotificationType::TeacherOffboardingNotice))->toBeFalse();
});
