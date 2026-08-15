<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
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

/*
| ⚠️ THE "QUESTION MANAGEMENT" AND "CROSS-EXAM OWNERSHIP" BLOCKS THAT USED TO
| LIVE HERE WERE DELETED WITH THE ROUTES THEY EXERCISED, and the deletion is the
| point rather than a casualty of it.
|
| They drove `POST|PUT|DELETE /exams/{exam}/questions`, four routes that wrote
| straight into the `questions` table with `questions.manage` as their only
| guard. After spec 008 that table is a bank shared across every exam, and those
| routes bypassed every rule the bank has: `DELETE` hard-deleted a question with
| recorded attempts, which FR-005 forbids in favour of disabling, and `POST`
| created one with no concept and no Bloom level, which FR-002 forbids.
|
| Their ownership check was `$question->exam_id !== $exam->id` — a comparison
| against the column that stops being written this release and is dropped in the
| next. The tests passed because they asserted the old model was intact.
|
| What replaces them: `BankReuseTest` (one question, three exams, one row),
| `QuestionTaggingTest` (FR-002), `QuestionEditSafetyTest` (FR-004) and
| `BankAccessTest` (isolation and the search constraint).
*/
