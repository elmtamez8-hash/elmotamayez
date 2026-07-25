<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

describe('exam management', function (): void {
    it('creates an exam', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/exams', [
            'title' => 'Midterm Exam',
            'description' => 'Covers chapters 1-5',
            'duration_minutes' => 45,
            'passing_score' => 70,
        ])->assertCreated()->assertJsonPath('title', 'Midterm Exam')
            ->assertJsonPath('status', 'draft');

        expect(Exam::where('title', 'Midterm Exam')->exists())->toBeTrue();
    });

    it('lists and shows exams', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $exam = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'title' => 'Final', 'status' => 'published', 'passing_score' => 50,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/exams')->assertOk()->assertJsonCount(1);
        $this->getJson("/api/v1/exams/{$exam->uuid}")->assertOk()->assertJsonPath('title', 'Final');
    });

    it('updates an exam', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $exam = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'title' => 'Old', 'status' => 'draft',
        ]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/exams/{$exam->uuid}", ['title' => 'New Title', 'passing_score' => 80])
            ->assertOk()->assertJsonPath('title', 'New Title')
            ->assertJsonPath('passing_score', 80);
    });

    it('publishes an exam', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $exam = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'title' => 'Draft Exam', 'status' => 'draft',
        ]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/exams/{$exam->uuid}/publish")
            ->assertOk()->assertJsonPath('status', 'published')
            ->assertJsonPath('is_published', true);
    });

    it('deletes an exam', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $exam = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'title' => 'To Delete', 'status' => 'draft',
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/exams/{$exam->uuid}")->assertNoContent();
        expect(Exam::where('id', $exam->id)->exists())->toBeFalse();
    });

    it('denies exam creation to students', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/exams', ['title' => 'X'])->assertForbidden();
    });
});

describe('question management', function (): void {
    it('lists questions of an exam with options', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $exam = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'title' => 'Exam', 'status' => 'draft',
        ]);
        $question = Question::create([
            'workspace_id' => $workspace->id, 'exam_id' => $exam->id, 'type' => 'mcq',
            'content' => 'Q1?', 'points' => 1,
        ]);
        QuestionOption::create([
            'workspace_id' => $workspace->id, 'question_id' => $question->id,
            'content' => 'A', 'is_correct' => true, 'order' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/exams/{$exam->uuid}/questions")
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.options.0.content', 'A');
    });

    it('creates a question with nested options', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $exam = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'title' => 'Exam', 'status' => 'draft',
        ]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/exams/{$exam->uuid}/questions", [
            'type' => 'mcq',
            'content' => 'What is 2+2?',
            'points' => 2,
            'options' => [
                ['content' => '4', 'is_correct' => true, 'order' => 1],
                ['content' => '5', 'is_correct' => false, 'order' => 2],
            ],
        ])->assertCreated()->assertJsonPath('content', 'What is 2+2?')
            ->assertJsonPath('options', function ($options): bool {
                return count($options) === 2;
            });

        expect(Question::where('exam_id', $exam->id)->count())->toBe(1)
            ->and(QuestionOption::where('question_id', Question::first()->id)->count())->toBe(2);
    });

    it('updates a question and replaces options', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $exam = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'title' => 'Exam', 'status' => 'draft',
        ]);
        $question = Question::create([
            'workspace_id' => $workspace->id, 'exam_id' => $exam->id, 'type' => 'mcq',
            'content' => 'Old Q', 'points' => 1,
        ]);
        QuestionOption::create([
            'workspace_id' => $workspace->id, 'question_id' => $question->id,
            'content' => 'Old', 'is_correct' => true, 'order' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/exams/{$exam->uuid}/questions/{$question->id}", [
            'content' => 'Updated Q',
            'options' => [
                ['content' => 'Yes', 'is_correct' => true, 'order' => 1],
                ['content' => 'No', 'is_correct' => false, 'order' => 2],
                ['content' => 'Maybe', 'is_correct' => false, 'order' => 3],
            ],
        ])->assertOk()->assertJsonPath('content', 'Updated Q');

        expect(QuestionOption::where('question_id', $question->id)->count())->toBe(3)
            ->and(QuestionOption::where('question_id', $question->id)->where('content', 'Old')->exists())->toBeFalse();
    });

    it('deletes a question', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $exam = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'title' => 'Exam', 'status' => 'draft',
        ]);
        $question = Question::create([
            'workspace_id' => $workspace->id, 'exam_id' => $exam->id, 'type' => 'mcq',
            'content' => 'Q', 'points' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/exams/{$exam->uuid}/questions/{$question->id}")->assertNoContent();
        expect(Question::where('id', $question->id)->exists())->toBeFalse();
    });

    it('denies question management to students', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $exam = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'title' => 'Exam', 'status' => 'draft',
        ]);
        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $this->getJson("/api/v1/exams/{$exam->uuid}/questions")->assertForbidden();
    });
});

describe('cross-exam ownership enforcement', function (): void {
    it('prevents updating a question that belongs to a different exam', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $examA = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'title' => 'A', 'status' => 'draft',
        ]);
        $examB = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'title' => 'B', 'status' => 'draft',
        ]);
        $questionOfB = Question::create([
            'workspace_id' => $workspace->id, 'exam_id' => $examB->id, 'type' => 'mcq', 'content' => 'Q', 'points' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/exams/{$examA->uuid}/questions/{$questionOfB->id}", ['content' => 'Hijacked'])
            ->assertNotFound();

        expect(Question::find($questionOfB->id)->content)->toBe('Q');
    });

    it('prevents deleting a question that belongs to a different exam', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $examA = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'title' => 'A', 'status' => 'draft',
        ]);
        $examB = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'title' => 'B', 'status' => 'draft',
        ]);
        $questionOfB = Question::create([
            'workspace_id' => $workspace->id, 'exam_id' => $examB->id, 'type' => 'mcq', 'content' => 'Q', 'points' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/exams/{$examA->uuid}/questions/{$questionOfB->id}")->assertNotFound();
        expect(Question::where('id', $questionOfB->id)->exists())->toBeTrue();
    });
});
