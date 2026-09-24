<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| `certificate_issued` from the chain the product actually runs: the student
| completes the last item → `CourseCompleted` → `IssueCertificateIfEligible` →
| `CertificateIssued` → the notification (`EveryNotificationTypeIsTestedTest`).
|
| ⚠️ The only earlier reference to this type was a FACTORY row in the
| notification-centre test — a feed rendering fixture, which says nothing about
| whether finishing a course ever tells anybody. The student here is a
| self-registered one: a member of no workspace, with a null context.
*/

it('tells the student their certificate was issued when they finish the course', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();

    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);

    $section = Section::create([
        'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
        'title' => 'قسم', 'status' => ContentStatus::Published,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspace->getKey(), 'section_id' => $section->getKey(), 'course_id' => $course->getKey(),
        'title' => 'فصل', 'status' => ContentStatus::Published,
    ]);

    $lesson = Lesson::create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'section_id' => $section->getKey(),
        'chapter_id' => $chapter->getKey(),
        'uuid' => Str::uuid(),
        'title' => 'الدرس الوحيد',
        'type' => LessonType::Embed->value,
        'status' => ContentStatus::Published,
        'external_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        'order' => 0,
    ]);

    $enrollment = Enrollment::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'student_user_id' => $student->getKey(),
    ]);

    Sanctum::actingAs($student);

    $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$lesson->uuid}/complete")
        ->assertOk();

    expect(Certificate::query()->withoutWorkspaceScope()->where('enrollment_id', $enrollment->getKey())->exists())
        ->toBeTrue();

    $row = assertNotifiedOnce($student, NotificationType::CertificateIssued);

    // The course title is a required template variable: a relation that resolved
    // null would have sent '' and the renderer would have dropped the message.
    expect((string) $row->body)->toContain((string) $course->title);
});
