<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use Laravel\Sanctum\Sanctum;

/*
| SC-008 · FR-018 · FR-019. The paper is built from what is still wrong.
*/

it('builds the paper from the standing mistakes and leaves out the fixed ones', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $workspaceId = (int) $workspace->getKey();

    $standing = [];

    // Five mistakes, two of them answered correctly since.
    foreach (range(1, 5) as $index) {
        $question = practiceQuestion($workspace, "سؤال {$index}؟");
        answerRow($workspaceId, $student, (int) $question->getKey(), correct: false);

        if ($index <= 2) {
            answerRow($workspaceId, $student, (int) $question->getKey(), correct: true);
        } else {
            $standing[] = (int) $question->getKey();
        }
    }

    Sanctum::actingAs($student);

    $response = $this->postJson('/api/v1/practice/from-mistakes', ['count' => 10]);

    $response->assertCreated();

    $attempt = Attempt::query()->where('uuid', $response->json('data.uuid'))->sole();

    expect($attempt->is_practice)->toBeTrue()
        // ⚠️ No exam behind it, and that is what keeps a revision session from
        // spending an official attempt and from firing ExamPassed.
        ->and($attempt->exam_id)->toBeNull()
        ->and($attempt->items()->pluck('question_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe(collect($standing)->sort()->values()->all());
});

it('refuses to build a paper when nothing is standing', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $question = practiceQuestion($workspace, 'أصلحته؟');

    answerRow((int) $workspace->getKey(), $student, (int) $question->getKey(), correct: false);
    answerRow((int) $workspace->getKey(), $student, (int) $question->getKey(), correct: true);

    Sanctum::actingAs($student);

    // 422 with a sentence, not an empty paper: an attempt with no questions is a
    // screen that says nothing and scores zero out of zero.
    $this->postJson('/api/v1/practice/from-mistakes')->assertStatus(422);
});

it('holds back a question sitting in an exam the student has not taken', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $workspaceId = (int) $workspace->getKey();

    $exam = Exam::factory()->create(['workspace_id' => $workspaceId, 'status' => 'published']);

    /*
    | ⚠️ FR-022أ. One bank question serves several exams, so a question missed
    | last month can also be on next week's paper. Practising it marks it
    | instantly and shows the explanation — which is the exam, with its answers,
    | handed over a week early.
    */
    $onNextExam = practiceQuestion($workspace, 'سؤال امتحان الأسبوع القادم؟');
    $free = practiceQuestion($workspace, 'سؤال حرّ؟');

    bankQuestion($workspace, $exam, ['content' => 'حشو؟']);
    $exam->items()->create([
        'workspace_id' => $workspaceId,
        'question_id' => $onNextExam->getKey(),
        'order' => 2,
    ]);

    answerRow($workspaceId, $student, (int) $onNextExam->getKey(), correct: false);
    answerRow($workspaceId, $student, (int) $free->getKey(), correct: false);

    Sanctum::actingAs($student);

    $response = $this->postJson('/api/v1/practice/from-mistakes');
    $attempt = Attempt::query()->where('uuid', $response->json('data.uuid'))->sole();

    expect($attempt->items()->pluck('question_id')->map(fn ($id) => (int) $id)->all())
        ->toBe([(int) $free->getKey()]);
});

it('leaves a disabled question in the notebook and out of the paper', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $workspaceId = (int) $workspace->getKey();

    $withdrawn = practiceQuestion($workspace, 'سؤالٌ سحبه المدرّس؟', ['is_active' => false]);
    $live = practiceQuestion($workspace, 'سؤالٌ قائم؟');

    answerRow($workspaceId, $student, (int) $withdrawn->getKey(), correct: false);
    answerRow($workspaceId, $student, (int) $live->getKey(), correct: false);

    Sanctum::actingAs($student);

    // The mistake happened and stays readable; what the teacher withdrew is not
    // put back in front of anyone.
    expect(collect($this->getJson('/api/v1/mistakes')->json('data'))->pluck('question.content')->all())
        ->toHaveCount(2);

    $response = $this->postJson('/api/v1/practice/from-mistakes');
    $attempt = Attempt::query()->where('uuid', $response->json('data.uuid'))->sole();

    expect($attempt->items()->pluck('question_id')->map(fn ($id) => (int) $id)->all())
        ->toBe([(int) $live->getKey()]);
});

it('keeps an essay out of a paper that marks itself', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $workspaceId = (int) $workspace->getKey();

    $essay = practiceQuestion($workspace, 'اشرح بالتفصيل؟', ['type' => 'essay']);

    // Marked by a person, so it is a real mistake — and it would push a practice
    // attempt into `pending_grading`, dropping work the student set themselves
    // into a queue the teacher never assigned.
    answerRow($workspaceId, $student, (int) $essay->getKey(), correct: false, overrides: [
        'requires_grading' => true,
        'graded_at' => now(),
    ]);

    Sanctum::actingAs($student);

    expect($this->getJson('/api/v1/mistakes')->json('data'))->toHaveCount(1);

    $this->postJson('/api/v1/practice/from-mistakes')->assertStatus(422);
});
