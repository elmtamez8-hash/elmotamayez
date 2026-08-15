<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Learning\Support\ExamGateSatisfaction;
use Laravel\Sanctum\Sanctum;

/*
| SC-020 · FR-026أ · FR-025. Revising costs nothing.
|
| ⚠️ THE SHIPPED COUNTER COUNTED EVERY ATTEMPT. It compared `max_attempts`
| against every row a student had on the exam, so the first revision run ate a
| graded chance — the feature built to help somebody prepare was the feature that
| stopped them sitting the paper.
*/

it('leaves both official attempts after three practice runs', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $lesson = enrolledLesson($workspace, $student);
    $workspaceId = (int) $workspace->getKey();

    foreach (range(1, 3) as $index) {
        practiceQuestion($workspace, "سؤال {$index}؟", ['lesson_id' => $lesson->getKey()]);
    }

    $exam = Exam::factory()->create([
        'workspace_id' => $workspaceId,
        'status' => 'published',
        'max_attempts' => 2,
    ]);

    // A question of its own, so the published exam withholds nothing from the
    // practice pool and the two halves of this test stay independent.
    $examQuestion = practiceQuestion($workspace, 'سؤال الاختبار؟', ['lesson_id' => $lesson->getKey()]);
    $exam->items()->create([
        'workspace_id' => $workspaceId,
        'question_id' => $examQuestion->getKey(),
        'order' => 1,
    ]);

    Sanctum::actingAs($student);

    foreach (range(1, 3) as $ignored) {
        $this->postJson('/api/v1/practice/exams', ['count' => 3])->assertCreated();
    }

    $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertCreated();
    $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertCreated();

    // And the third is refused, which is the other half of the assertion: a
    // limit that never bites would pass this test just as well.
    $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertStatus(422);

    expect(Attempt::query()->where('student_user_id', $student->getKey())->where('is_practice', true)->count())->toBe(3)
        ->and(Attempt::query()->where('exam_id', $exam->getKey())->where('is_practice', false)->count())->toBe(2);
});

it('keeps a practice run out of the graded record', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $lesson = enrolledLesson($workspace, $student);

    practiceQuestion($workspace, 'واحدٌ زائد واحد؟', ['lesson_id' => $lesson->getKey()]);

    Sanctum::actingAs($student);

    $built = $this->postJson('/api/v1/practice/exams', ['count' => 1])->json('data');
    $correct = collect($built['questions'][0]['options'])->firstWhere('content', 'صح')['id'];

    $this->postJson("/api/v1/attempts/{$built['uuid']}/submit", [
        'answers' => [['question_id' => $built['questions'][0]['id'], 'selected_option_ids' => [$correct]]],
    ])->assertOk();

    $attempt = Attempt::query()->where('uuid', $built['uuid'])->sole();

    /*
    | ⚠️ A FULL MARK AND `passed` STILL FALSE. `ExamPassed` is the certificate
    | contract, and a revision run that fired it would issue a certificate for a
    | paper the student set themselves — and the listener is idempotent, so it
    | will not issue a second one, but nothing withdraws the first.
    */
    expect((float) $attempt->score)->toBe(100.0)
        ->and($attempt->passed)->toBeFalse()
        ->and($attempt->exam_id)->toBeNull();
});

it('does not let a practice sitting of a real exam satisfy its gate', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $exam = Exam::factory()->create(['workspace_id' => $workspace->getKey(), 'status' => 'published']);

    /*
    | ⚠️ THE ROW THE `exam_id IS NULL` GUARD DOES NOT CATCH. A self-generated
    | paper has no exam behind it, so every reader that checks for null is safe
    | from those — but the same exam may be sat officially and again for
    | revision, and THAT row carries a real `exam_id`. Left unfiltered it unlocks
    | the next chapter and, under a pass gate, records a pass, from a paper the
    | student marked themselves with the explanations in front of them.
    */
    app(StartAttempt::class)->handle($exam, $student, isPractice: true)
        ->forceFill(['submitted_at' => now(), 'passed' => true, 'status' => Attempt::STATUS_GRADED])
        ->save();

    expect(ExamGateSatisfaction::metBy((int) $exam->getKey(), ExamGate::Pass, (int) $student->getKey()))->toBeFalse()
        ->and(ExamGateSatisfaction::metBy((int) $exam->getKey(), ExamGate::Attempt, (int) $student->getKey()))->toBeFalse();
});
