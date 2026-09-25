<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ `POST /exams/{uuid}/attempts` never asked the course's sequence.
|
| An exam placed as an item in a sequential course is locked on the curriculum
| until the items before it are done — `LessonGate::for()` — while its own
| endpoint took the uuid and started a GRADED attempt: a student skipped straight
| to the final exam, spent an attempt, and on a pass ticked the item the sequence
| was holding shut. `StartAttempt` now asks the same gate, never a re-derived one.
|
| Both student shapes are measured: the self-registered one (a member of no
| workspace, so the context is null) and the stamped one (`addWorkspaceMember`,
| a `student` pivot row and a `last_workspace_id`).
*/

/**
 * A sequential course: «الدرس الأول» (article), then the exam.
 *
 * @return array{0: Exam, 1: Lesson}
 */
function sequenceGateCourse(Workspace $workspace): array
{
    $course = Course::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'is_sequential' => true,
    ]);

    $exam = Exam::factory()->create([
        'workspace_id' => $workspace->id,
        'course_id' => $course->id,
        'status' => 'published',
        'max_attempts' => 3,
    ]);
    bankQuestion($workspace, $exam);

    $section = Section::create([
        'workspace_id' => $workspace->id, 'course_id' => $course->id,
        'title' => 'الوحدة', 'status' => ContentStatus::Published, 'order' => 1,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'الفصل', 'status' => ContentStatus::Published, 'order' => 1,
    ]);

    $article = Lesson::create([
        'workspace_id' => $workspace->id, 'course_id' => $course->id,
        'section_id' => $section->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'الدرس الأول', 'type' => 'article', 'content' => 'نصّ',
        'status' => ContentStatus::Published, 'order' => 1,
    ]);

    Lesson::create([
        'workspace_id' => $workspace->id, 'course_id' => $course->id,
        'section_id' => $section->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'الاختبار', 'type' => 'exam',
        'reference_id' => $exam->id, 'exam_gate' => ExamGate::Attempt,
        'status' => ContentStatus::Published, 'order' => 2,
    ]);

    return [$exam, $article];
}

function sequenceGateEnrol(Workspace $workspace, Exam $exam, User $student): Enrollment
{
    return Enrollment::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $workspace->id,
        'course_id' => $exam->course_id,
        'student_user_id' => $student->id,
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);
}

it('refuses a self-registered student the exam while the item before it is unfinished, then allows it', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    [$exam, $article] = sequenceGateCourse($workspace);

    // A member of no workspace, as every self-registered buyer is: the context
    // resolves to null and the workspace scope is inert.
    $student = User::factory()->create();
    $enrollment = sequenceGateEnrol($workspace, $exam, $student);

    Sanctum::actingAs($student);
    app()->forgetInstance(WorkspaceContext::class);

    $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")
        ->assertStatus(422)
        ->assertJsonPath('message', 'أكمِل «الدرس الأول» أولاً — هذا الكورس متسلسل.');

    expect(Attempt::query()->withoutWorkspaceScope()->where('exam_id', $exam->id)->count())->toBe(0);

    app(MarkLessonComplete::class)->handle($enrollment, (int) $article->id);

    $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertCreated();
});

it('refuses a stamped student the same way', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    [$exam, $article] = sequenceGateCourse($workspace);

    $student = $this->addWorkspaceMember($workspace, 'student');
    $enrollment = sequenceGateEnrol($workspace, $exam, $student);

    Sanctum::actingAs($student);

    $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertStatus(422);

    app(MarkLessonComplete::class)->handle($enrollment, (int) $article->id);

    $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertCreated();
});

it('leaves the author of the workspace free to sit their own exam', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$exam] = sequenceGateCourse($workspace);

    // Contrived on purpose: an enrolment row on the author is the only way the
    // sequence is asked of them at all, and the answer must be the exemption.
    sequenceGateEnrol($workspace, $exam, $owner);

    Sanctum::actingAs($owner);
    $this->setCurrentWorkspace($workspace, $owner);

    $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertCreated();
});

it('does not gate an exam whose only placement the student cannot see', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    [$exam] = sequenceGateCourse($workspace);

    // The exam item goes back to draft: it is not in the student's tree, so it
    // is not a placement that can lock anything.
    Lesson::query()->withoutWorkspaceScope()
        ->where('reference_id', $exam->id)
        ->update(['status' => ContentStatus::Draft]);

    $student = $this->addWorkspaceMember($workspace, 'student');
    sequenceGateEnrol($workspace, $exam, $student);

    Sanctum::actingAs($student);

    $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertCreated();
});
