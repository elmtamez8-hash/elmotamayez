<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * @return array{0: Exam, 1: array<int, int>} the exam and a question_id => correct option_id map
 */
function attemptRulesExam(int $workspaceId, ?int $courseId = null, int $maxAttempts = 3, int $passingScore = 50): array
{
    $exam = Exam::create([
        'workspace_id' => $workspaceId,
        'uuid' => Str::uuid(),
        'course_id' => $courseId,
        'title' => 'Rules Exam',
        'duration_minutes' => 30,
        'passing_score' => $passingScore,
        'max_attempts' => $maxAttempts,
        'status' => 'published',
    ]);

    $correct = [];

    for ($i = 1; $i <= 2; $i++) {
        $question = Question::create([
            'workspace_id' => $workspaceId,
            'exam_id' => $exam->id,
            'type' => 'mcq',
            'difficulty' => 'easy',
            'content' => "Question {$i}?",
            'points' => 1,
        ]);

        $correct[$question->id] = QuestionOption::create([
            'workspace_id' => $workspaceId,
            'question_id' => $question->id,
            'content' => 'Right',
            'is_correct' => true,
            'order' => 1,
        ])->id;

        QuestionOption::create([
            'workspace_id' => $workspaceId,
            'question_id' => $question->id,
            'content' => 'Wrong',
            'is_correct' => false,
            'order' => 2,
        ]);
    }

    return [$exam, $correct];
}

function attemptRulesCourse(int $workspaceId): Course
{
    $course = Course::factory()->create([
        'workspace_id' => $workspaceId,
        'status' => 'published',
        'is_sequential' => false,
    ]);

    $section = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => 'Section 1', 'order' => 1,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'section_id' => $section->id, 'title' => 'Chapter 1', 'order' => 1,
    ]);

    Lesson::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'section_id' => $section->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'Lesson 1', 'type' => 'article', 'order' => 1,
    ]);

    return $course->fresh();
}

describe('attempt rules', function (): void {
    it('scores skipped questions as zero instead of shrinking the total', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        [$exam, $correct] = attemptRulesExam($workspace->id);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $attemptUuid = $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")
            ->assertCreated()
            ->json('attempt.uuid');

        // Answer only the first of the two questions, correctly.
        $questionId = array_key_first($correct);

        $this->postJson("/api/v1/attempts/{$attemptUuid}/submit", [
            'answers' => [
                ['question_id' => $questionId, 'selected_option_ids' => [$correct[$questionId]]],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('score', 50)
            ->assertJsonPath('passed', true);
    });

    it('rejects an attempt once max_attempts is used up', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        [$exam, $correct] = attemptRulesExam($workspace->id, maxAttempts: 1);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $attemptUuid = $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")
            ->assertCreated()
            ->json('attempt.uuid');

        $this->postJson("/api/v1/attempts/{$attemptUuid}/submit", [
            'answers' => collect($correct)->map(fn (int $optionId, int $questionId) => [
                'question_id' => $questionId,
                'selected_option_ids' => [$optionId],
            ])->values()->all(),
        ])->assertOk();

        $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")
            ->assertStatus(422)
            ->assertJsonPath('message', 'You have used all 1 attempts for this exam.');

        expect(Attempt::where('exam_id', $exam->id)->count())->toBe(1);
    });

    it('links the student enrollment so passing a course exam issues a certificate', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = attemptRulesCourse($workspace->id);

        $student = $this->addWorkspaceMember($workspace, 'student');
        $enrollment = $this->createEnrollment($workspace, $course, $student);

        [$exam, $correct] = attemptRulesExam($workspace->id, courseId: $course->id);

        Sanctum::actingAs($student);

        $attemptUuid = $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")
            ->assertCreated()
            ->json('attempt.uuid');

        $attempt = Attempt::where('uuid', $attemptUuid)->firstOrFail();
        expect($attempt->enrollment_id)->toBe($enrollment->id);

        $this->postJson("/api/v1/attempts/{$attemptUuid}/submit", [
            'answers' => collect($correct)->map(fn (int $optionId, int $questionId) => [
                'question_id' => $questionId,
                'selected_option_ids' => [$optionId],
            ])->values()->all(),
        ])->assertOk()->assertJsonPath('passed', true);

        $certificate = Certificate::where('enrollment_id', $enrollment->id)->first();

        expect($certificate)->not->toBeNull()
            ->and($certificate->issue_reason)->toBe('exam_passed')
            ->and($certificate->exam_attempt_id)->toBe($attempt->id);
    });

    it('keeps option ids when a question is edited so graded answers stay resolvable', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        [$exam] = attemptRulesExam($workspace->id);

        $this->setCurrentWorkspace($workspace, $owner);
        Sanctum::actingAs($owner);

        $question = Question::where('exam_id', $exam->id)->firstOrFail();
        $optionIdsBefore = $question->options()->orderBy('id')->pluck('id')->all();

        $this->putJson("/api/v1/exams/{$exam->uuid}/questions/{$question->id}", [
            'content' => 'Reworded question?',
            'options' => [
                ['content' => 'Right (reworded)', 'is_correct' => true],
                ['content' => 'Wrong (reworded)', 'is_correct' => false],
            ],
        ])->assertOk();

        expect($question->options()->orderBy('id')->pluck('id')->all())->toBe($optionIdsBefore)
            ->and($question->options()->where('is_correct', true)->value('content'))->toBe('Right (reworded)');
    });
});

describe('enrollment lesson boundary', function (): void {
    it('refuses to complete a lesson that belongs to another course', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();

        $enrolledCourse = attemptRulesCourse($workspace->id);
        $otherCourse = attemptRulesCourse($workspace->id);

        $student = $this->addWorkspaceMember($workspace, 'student');
        $enrollment = $this->createEnrollment($workspace, $enrolledCourse, $student);

        $foreignLesson = Lesson::where('course_id', $otherCourse->id)->firstOrFail();

        Sanctum::actingAs($student);

        $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$foreignLesson->uuid}/complete")
            ->assertStatus(404);

        expect(Enrollment::find($enrollment->id)->progress_pct)->toBe(0)
            ->and(Enrollment::find($enrollment->id)->status)->toBe('active');
    });
});
