<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use App\Modules\Certificates\Actions\IssueCertificate;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function createExamWithQuestions(int $workspaceId, int $questionCount = 2): array
{
    $exam = Exam::create([
        'workspace_id' => $workspaceId,
        'uuid' => Str::uuid(),
        'course_id' => null,
        'title' => 'Final Exam',
        'description' => 'Test exam',
        'duration_minutes' => 30,
        'passing_score' => 50,
        'max_attempts' => 3,
        'shuffle_questions' => false,
        'shuffle_answers' => false,
        'status' => 'published',
    ]);

    $correctOptionIds = [];

    for ($i = 1; $i <= $questionCount; $i++) {
        $question = Question::create([
            'workspace_id' => $workspaceId,
            'exam_id' => $exam->id,
            'type' => 'mcq',
            'difficulty' => 'easy',
            'content' => "Question {$i}?",
            'points' => 1,
        ]);

        $correct = QuestionOption::create([
            'workspace_id' => $workspaceId,
            'question_id' => $question->id,
            'content' => 'Correct answer',
            'is_correct' => true,
            'order' => 1,
        ]);
        $correctOptionIds[$question->id] = $correct->id;

        QuestionOption::create([
            'workspace_id' => $workspaceId,
            'question_id' => $question->id,
            'content' => 'Wrong answer',
            'is_correct' => false,
            'order' => 2,
        ]);
    }

    return [$exam, $correctOptionIds];
}

describe('exam submission', function (): void {
    it('starts an attempt and returns randomized questions', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        [$exam] = createExamWithQuestions($workspace->id, 2);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $response = $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")
            ->assertCreated()
            ->assertJsonStructure(['attempt' => ['uuid', 'status'], 'questions']);

        expect($response->json('questions'))->toHaveCount(2)
            ->and($response->json('questions.0.options'))->toHaveCount(2)
            ->and(Attempt::where('exam_id', $exam->id)->count())->toBe(1);
    });

    it('grades a passing attempt correctly', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        [$exam, $correctOptionIds] = createExamWithQuestions($workspace->id, 2);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $startResp = $this->postJson("/api/v1/exams/{$exam->uuid}/attempts");
        $attemptUuid = $startResp->json('attempt.uuid');

        $answers = [];
        foreach ($correctOptionIds as $questionId => $optionId) {
            $answers[] = ['question_id' => $questionId, 'selected_option_ids' => [$optionId]];
        }

        $this->postJson("/api/v1/attempts/{$attemptUuid}/submit", ['answers' => $answers])
            ->assertOk()
            ->assertJsonPath('status', 'graded')
            ->assertJsonPath('passed', true)
            ->assertJsonPath('score', 100);

        expect(Answer::where('attempt_id', Attempt::where('uuid', $attemptUuid)->first()->id)->count())->toBe(2);
    });

    it('grades a failing attempt correctly', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        [$exam, $correctOptionIds] = createExamWithQuestions($workspace->id, 2);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $startResp = $this->postJson("/api/v1/exams/{$exam->uuid}/attempts");
        $attemptUuid = $startResp->json('attempt.uuid');

        // Submit wrong answers.
        $answers = [];
        foreach ($correctOptionIds as $questionId => $optionId) {
            // Pick the wrong option (order=2)
            $wrongOption = QuestionOption::where('question_id', $questionId)->where('is_correct', false)->first();
            $answers[] = ['question_id' => $questionId, 'selected_option_ids' => [$wrongOption->id]];
        }

        $this->postJson("/api/v1/attempts/{$attemptUuid}/submit", ['answers' => $answers])
            ->assertOk()
            ->assertJsonPath('passed', false)
            ->assertJsonPath('score', 0);
    });

    it('prevents submitting the same attempt twice', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        [$exam, $correctOptionIds] = createExamWithQuestions($workspace->id, 1);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $attemptUuid = $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")->json('attempt.uuid');

        $answers = [['question_id' => array_key_first($correctOptionIds), 'selected_option_ids' => [reset($correctOptionIds)]]];

        $this->postJson("/api/v1/attempts/{$attemptUuid}/submit", ['answers' => $answers])->assertOk();
        $this->postJson("/api/v1/attempts/{$attemptUuid}/submit", ['answers' => $answers])->assertStatus(422);
    });
});

describe('certificate generation', function (): void {
    it('issues a certificate automatically when a course is completed', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->published()->create(['workspace_id' => $workspace->id, 'is_sequential' => false]);

        // Published at every level: a draft is outside the progress denominator,
        // so a course built without saying so can never complete and this test
        // would be asserting against a certificate that was right not to issue.
        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id, 'title' => 'S',
            'status' => ContentStatus::Published, 'order' => 1,
        ]);
        $chapter = Chapter::create([
            'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id, 'title' => 'C',
            'status' => ContentStatus::Published, 'order' => 1,
        ]);
        $lesson = Lesson::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id, 'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => 'L1', 'type' => 'article', 'status' => ContentStatus::Published,
            'content' => 'x', 'order' => 1,
        ]);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $enrollment = Enrollment::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
            'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
        ]);

        // Complete the lesson → course completes → certificate issued.
        $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$lesson->uuid}/complete")
            ->assertJsonPath('course_completed', true);

        $cert = Certificate::where('enrollment_id', $enrollment->id)->first();
        expect($cert)->not->toBeNull()
            ->and($cert->issue_reason)->toBe('course_completed')
            ->and($cert->verification_code)->toHaveLength(40)
            ->and($cert->certificate_number)->toStartWith('CERT-');
    });

    it('is idempotent — issuing twice produces one certificate', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->published()->create(['workspace_id' => $workspace->id, 'is_sequential' => false]);

        $student = $this->addWorkspaceMember($workspace, 'student');

        $enrollment = Enrollment::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
            'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
        ]);

        $action = app(IssueCertificate::class);
        $cert1 = $action->handle($enrollment, 'course_completed');
        $cert2 = $action->handle($enrollment, 'exam_passed');

        expect($cert1->id)->toBe($cert2->id)
            ->and(Certificate::where('enrollment_id', $enrollment->id)->count())->toBe(1);
    });

    it('issues a certificate when an exam is passed', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->published()->create(['workspace_id' => $workspace->id, 'is_sequential' => false]);

        $student = $this->addWorkspaceMember($workspace, 'student');

        $enrollment = Enrollment::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
            'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
        ]);

        [$exam, $correctOptionIds] = createExamWithQuestions($workspace->id, 1);
        $exam->update(['course_id' => $course->id]);

        // Start attempt, then manually fire the ExamPassed event via the GradeAttempt action.
        $attempt = Attempt::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'exam_id' => $exam->id,
            'enrollment_id' => $enrollment->id, 'student_user_id' => $student->id, 'status' => 'in_progress',
            'random_seed' => 12345, 'started_at' => now(),
        ]);

        $answers = [];
        foreach ($correctOptionIds as $qId => $optId) {
            $answers[] = ['question_id' => $qId, 'selected_option_ids' => [$optId]];
        }

        app(GradeAttempt::class)->handle($attempt, $answers);

        $cert = Certificate::where('enrollment_id', $enrollment->id)->first();
        expect($cert)->not->toBeNull()
            ->and($cert->issue_reason)->toBe('exam_passed')
            ->and($cert->exam_attempt_id)->toBe($attempt->id);
    });
});

describe('certificate verification', function (): void {
    it('verifies a certificate publicly by code', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->published()->create(['workspace_id' => $workspace->id, 'is_sequential' => false]);

        $student = $this->addWorkspaceMember($workspace, 'student');

        $enrollment = Enrollment::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
            'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
        ]);

        $cert = app(IssueCertificate::class)->handle($enrollment, 'course_completed');

        // Public endpoint — no auth.
        $this->getJson("/api/v1/certificates/verify/{$cert->verification_code}")
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('certificate.certificate_number', $cert->certificate_number);
    });

    it('rejects an invalid verification code', function (): void {
        $this->getJson('/api/v1/certificates/verify/nonexistent-code-123')
            ->assertNotFound()
            ->assertJsonPath('valid', false);
    });
});
