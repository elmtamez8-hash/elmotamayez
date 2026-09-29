<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Actions\IssuePlaybackGrant;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 010 · FR-005 on the LESSON doors — the page (`/learn/lessons/{uuid}`), the
| video (`IssuePlaybackGrant::mayWatch()` and its bulk twin) and the audience
| exemption inside `LessonAudience`.
|
| ⛔ Each of them had an author branch of its own, asked as «a non-student pivot
| role in the lesson's workspace» and ABOVE every status check — so an assistant
| confined to one course opened the DRAFT lessons of every other course in the
| workspace by uuid, and was granted their videos, while `CoursePolicy` refused
| them that course's page. The scope narrows the staff branch only: outside it
| the assistant takes the student's route like anybody else.
|
| ⚠️ BOTH DIRECTIONS IN EVERY TEST — «far is refused» alone is green against an
| assistant refused everything; «near is open» beside it is what makes the
| refusal mean «outside your scope».
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->near = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'status' => 'draft']);
    $this->far = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->nearDraft = scopedLessonIn($this->workspace, $this->near, ['status' => 'draft']);
    $this->farDraft = scopedLessonIn($this->workspace, $this->far, ['status' => 'draft']);
    // Published and paid: a stranger needs an enrolment for it.
    $this->farPaid = scopedLessonIn($this->workspace, $this->far);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

/** @param  array<string, mixed>  $attributes */
function scopedLessonIn(Workspace $workspace, Course $course, array $attributes = []): Lesson
{
    $section = Section::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
    ]);
    $chapter = Chapter::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'section_id' => $section->getKey(),
    ]);

    return Lesson::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'section_id' => $section->getKey(),
        'chapter_id' => $chapter->getKey(),
        'type' => 'video',
        ...$attributes,
    ]);
}

function confineLessonAssistantTo(Course $course): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => $course->getKey(),
    ]);

    // The directory memoises per request; a fresh container is the next request.
    app()->forgetScopedInstances();
}

function lessonPageStatus(Lesson $lesson): int
{
    return test()->getJson("/api/v1/learn/lessons/{$lesson->uuid}")->status();
}

function watchesLesson(Lesson $lesson, User $viewer): bool
{
    return app(IssuePlaybackGrant::class)->mayWatch($lesson->fresh() ?? $lesson, $viewer);
}

function watchesLessonInBulk(Lesson $lesson, User $viewer): bool
{
    return app(IssuePlaybackGrant::class)->mayWatchMany([$lesson->fresh() ?? $lesson], $viewer)[(int) $lesson->getKey()] ?? false;
}

it('opens the drafts of a confined assistant\'s own course and refuses every other course\'s', function (): void {
    confineLessonAssistantTo($this->near);
    Sanctum::actingAs($this->assistant);

    expect(lessonPageStatus($this->nearDraft))->toBe(200)
        ->and(watchesLesson($this->nearDraft, $this->assistant))->toBeTrue()
        ->and(watchesLessonInBulk($this->nearDraft, $this->assistant))->toBeTrue();

    $this->getJson("/api/v1/learn/lessons/{$this->farDraft->uuid}")
        ->assertNotFound()
        ->assertJsonPath('code', 'not_enrolled');

    expect(watchesLesson($this->farDraft, $this->assistant))->toBeFalse()
        ->and(watchesLessonInBulk($this->farDraft, $this->assistant))->toBeFalse();
});

it('treats a confined assistant as a stranger to another course\'s published paid lesson', function (): void {
    confineLessonAssistantTo($this->near);
    Sanctum::actingAs($this->assistant);

    expect(lessonPageStatus($this->farPaid))->toBe(404)
        ->and(watchesLesson($this->farPaid, $this->assistant))->toBeFalse()
        ->and(watchesLessonInBulk($this->farPaid, $this->assistant))->toBeFalse();
});

it('opens every lesson in the workspace to an unconfined assistant and to the owner', function (): void {
    foreach ([$this->assistant, $this->owner] as $staff) {
        Sanctum::actingAs($staff);

        foreach ([$this->nearDraft, $this->farDraft, $this->farPaid] as $lesson) {
            expect(lessonPageStatus($lesson))->toBe(200)
                ->and(watchesLesson($lesson, $staff))->toBeTrue()
                ->and(watchesLessonInBulk($lesson, $staff))->toBeTrue();
        }
    }
});

it('leaves an enrolled student\'s access to the far course untouched', function (): void {
    confineLessonAssistantTo($this->near);

    $student = User::factory()->create(['platform_role' => 'student']);
    $this->createEnrollment($this->workspace, $this->far, $student);

    Sanctum::actingAs($student);

    expect(lessonPageStatus($this->farPaid))->toBe(200)
        ->and(watchesLesson($this->farPaid, $student))->toBeTrue()
        ->and(watchesLessonInBulk($this->farPaid, $student))->toBeTrue()
        // …and a draft stays shut to them, as it always was.
        ->and(lessonPageStatus($this->farDraft))->toBe(404)
        ->and(watchesLesson($this->farDraft, $student))->toBeFalse();
});

it('refuses another workspace\'s draft to an assistant, confined or not', function (): void {
    [$other, $otherOwner] = $this->createWorkspaceWithOwner();

    $foreign = app(WorkspaceContext::class)->forWorkspace($other, function () use ($other): Lesson {
        $course = Course::factory()->create(['workspace_id' => $other->getKey(), 'status' => 'draft']);

        return scopedLessonIn($other, $course, ['status' => 'draft']);
    });

    Sanctum::actingAs($this->assistant);
    expect(lessonPageStatus($foreign))->toBe(404)
        ->and(watchesLesson($foreign, $this->assistant))->toBeFalse()
        ->and(watchesLessonInBulk($foreign, $this->assistant))->toBeFalse();

    confineLessonAssistantTo($this->near);
    Sanctum::actingAs($this->assistant->refresh());
    expect(lessonPageStatus($foreign))->toBe(404)
        ->and(watchesLesson($foreign, $this->assistant))->toBeFalse();
});

it('scopes the session-manager arm of a recording to the assistant\'s courses', function (): void {
    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);

    $recordingIn = function (Course $course) use ($teacher): Lesson {
        $session = ClassSession::factory()->create([
            'teacher_profile_id' => $teacher->getKey(),
            'course_id' => $course->getKey(),
            'starts_at' => CarbonImmutable::now()->subDays(2),
            'ends_at' => CarbonImmutable::now()->subDays(2)->addHour(),
        ]);

        return scopedLessonIn($this->workspace, $course, ['class_session_id' => $session->getKey()]);
    };

    $nearRecording = $recordingIn($this->near);
    $farRecording = $recordingIn($this->far);

    $this->assistant->givePermissionTo(Permissions::SESSIONS_MANAGE);
    confineLessonAssistantTo($this->near);
    Sanctum::actingAs($this->assistant);

    expect(watchesLesson($nearRecording, $this->assistant))->toBeTrue()
        ->and(watchesLessonInBulk($nearRecording, $this->assistant))->toBeTrue()
        ->and(watchesLesson($farRecording, $this->assistant))->toBeFalse()
        ->and(watchesLessonInBulk($farRecording, $this->assistant))->toBeFalse()
        // The page agrees with the video on both.
        ->and(lessonPageStatus($nearRecording))->toBe(200)
        ->and(lessonPageStatus($farRecording))->toBe(404);
});

it('exempts a confined assistant from a group restriction in their own course only', function (): void {
    $restrictedIn = function (Course $course): Lesson {
        $lesson = scopedLessonIn($this->workspace, $course, ['is_free' => true]);

        $cohort = Cohort::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $course->getKey(),
            'created_by' => $this->owner->getKey(),
        ]);

        LessonCohortScope::create([
            'workspace_id' => $this->workspace->getKey(),
            'lesson_id' => $lesson->getKey(),
            'cohort_id' => $cohort->getKey(),
        ]);

        return $lesson;
    };

    // Published, so the draft line is not what decides either answer.
    $this->near->forceFill(['status' => 'published'])->save();
    $nearFree = $restrictedIn($this->near);
    $farFree = $restrictedIn($this->far);

    confineLessonAssistantTo($this->near);
    Sanctum::actingAs($this->assistant);

    // Free for any account — except the one group it was kept for, which the
    // assistant is not in; the author exemption is what opens the near one.
    expect(lessonPageStatus($nearFree))->toBe(200)
        ->and(watchesLesson($nearFree, $this->assistant))->toBeTrue()
        ->and(lessonPageStatus($farFree))->toBe(404)
        ->and(watchesLesson($farFree, $this->assistant))->toBeFalse()
        ->and(watchesLessonInBulk($farFree, $this->assistant))->toBeFalse();
});
