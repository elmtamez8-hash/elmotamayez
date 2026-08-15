<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use Laravel\Sanctum\Sanctum;

/*
| SC-020 · FR-022أ. Tomorrow's paper does not come back as today's revision.
|
| ⚠️ THE WHOLE DEFECT IS THAT BOTH HALVES ARE CORRECT ON THEIR OWN. One bank
| question may sit in several exams, and a self-generated paper is marked
| instantly WITH the explanations — put together, a student who asks for the
| concept and difficulty of next week's exam is handed that exam and its answer
| key, a week early, by a feature built to help them revise.
*/

it('withholds a question that sits in a published exam the student has not sat', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $lesson = enrolledLesson($workspace, $student);
    $workspaceId = (int) $workspace->getKey();

    $onExam = practiceQuestion($workspace, 'سؤال امتحان الغد؟', ['lesson_id' => $lesson->getKey(), 'difficulty' => 'medium']);
    $free = practiceQuestion($workspace, 'سؤالٌ حرّ؟', ['lesson_id' => $lesson->getKey(), 'difficulty' => 'medium']);

    $exam = Exam::factory()->create(['workspace_id' => $workspaceId, 'status' => 'published']);
    $exam->items()->create([
        'workspace_id' => $workspaceId,
        'question_id' => $onExam->getKey(),
        'order' => 1,
    ]);

    Sanctum::actingAs($student);

    // The student asks with EXACTLY the criteria the exam was built from — the
    // request the leak needs, and the one a student preparing would naturally make.
    $questions = $this->postJson('/api/v1/practice/exams', ['count' => 10, 'difficulty' => 'medium'])
        ->json('data.questions');

    expect(collect($questions)->pluck('content')->all())->toBe(['سؤالٌ حرّ؟']);
});

it('lets the question into the pool once the student has sat that exam', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $lesson = enrolledLesson($workspace, $student);
    $workspaceId = (int) $workspace->getKey();

    $onExam = practiceQuestion($workspace, 'سؤال امتحانٍ جلستُ له؟', ['lesson_id' => $lesson->getKey()]);

    $exam = Exam::factory()->create(['workspace_id' => $workspaceId, 'status' => 'published']);
    $exam->items()->create([
        'workspace_id' => $workspaceId,
        'question_id' => $onExam->getKey(),
        'order' => 1,
    ]);

    Sanctum::actingAs($student);

    $this->postJson('/api/v1/practice/exams', ['count' => 10])->assertStatus(422);

    /*
    | They sit it. Withholding it afterwards protects nothing — they have seen
    | every question on the paper — and blocks revision of the one thing they
    | most need to revise.
    |
    | ⚠️ `submitted_at`, not `passed`. "Has seen the paper" is what the rule is
    | about, and a student who sat it and failed has seen every question on it;
    | keying the release on passing would lock revision away from exactly the
    | person it exists for.
    */
    Attempt::create([
        'workspace_id' => $workspaceId,
        'exam_id' => $exam->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => Attempt::STATUS_GRADED,
        'is_practice' => false,
        'score' => 0,
        'max_score' => 100,
        'passed' => false,
        'random_seed' => 1,
        'started_at' => now(),
        'submitted_at' => now(),
    ]);

    expect($this->postJson('/api/v1/practice/exams', ['count' => 10])->json('data.questions'))
        ->toHaveCount(1);
});

it('keeps a draft exam from withholding anything', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $lesson = enrolledLesson($workspace, $student);
    $workspaceId = (int) $workspace->getKey();

    $question = practiceQuestion($workspace, 'في مسوّدةٍ لم تُنشر؟', ['lesson_id' => $lesson->getKey()]);

    // A draft is not a paper anybody will sit. Withholding on it would let a
    // teacher's unfinished draft quietly shrink every student's revision pool,
    // with nothing on any screen to explain why.
    $exam = Exam::factory()->create(['workspace_id' => $workspaceId, 'status' => 'draft']);
    $exam->items()->create([
        'workspace_id' => $workspaceId,
        'question_id' => $question->getKey(),
        'order' => 1,
    ]);

    Sanctum::actingAs($student);

    expect($this->postJson('/api/v1/practice/exams', ['count' => 10])->json('data.questions'))
        ->toHaveCount(1);
});
