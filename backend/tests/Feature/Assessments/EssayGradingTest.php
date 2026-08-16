<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\RubricCriterion;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| FR-027 · FR-029 · FR-030 · SC-011. A person reads the essay, and only then is
| there a result.
*/

it('adds the marks to the auto-score and tells the student once', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);

    // 10 for the essay, 1 for the multiple choice: the student has the mcq
    // right, so the auto-score is 1/11 — about 9% — and a screen that showed it
    // as their result would be reporting a fail on a paper they may have aced.
    [, $attempt, $essay] = sitEssayExam($workspace, $student, essayPoints: 10, passingScore: 60);

    expect($attempt->status)->toBe(Attempt::STATUS_PENDING_GRADING)
        ->and($attempt->passed)->toBeFalse();

    Sanctum::actingAs($owner);

    $answer = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('question_id', $essay->getKey())
        ->sole();

    $this->postJson("/api/v1/manage/grading/answers/{$answer->uuid}", [
        'marks' => [['points' => 8, 'comment' => 'شرحٌ جيّد، ينقصه مثال.']],
    ])->assertOk();

    $attempt->refresh();

    // 9 of 11 — the essay's 8 plus the machine's 1.
    expect($attempt->status)->toBe(Attempt::STATUS_GRADED)
        ->and(round((float) $attempt->score))->toBe(82.0)
        ->and($attempt->passed)->toBeTrue()
        ->and($attempt->finalized_at)->not->toBeNull();

    // FR-030: the student hears the result, and hears it once.
    expect(Notification::query()
        ->where('recipient_user_id', $student->getKey())
        ->where('type', NotificationType::ExamResult->value)
        ->count())->toBe(1);
});

it('splits the marks across the rubric and keeps the comment on each line', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [, $attempt, $essay] = sitEssayExam($workspace, $student, essayPoints: 5);

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/manage/bank/questions/{$essay->uuid}/rubric", [
        'criteria' => [
            ['label' => 'المحتوى', 'max_points' => 2.5],
            ['label' => 'اللغة', 'max_points' => 2.5],
        ],
    ])->assertOk();

    $criteria = RubricCriterion::query()->where('question_id', $essay->getKey())->orderBy('id')->get();

    $answer = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('question_id', $essay->getKey())
        ->sole();

    $this->postJson("/api/v1/manage/grading/answers/{$answer->uuid}", [
        'marks' => [
            ['criterion_id' => (int) $criteria[0]->getKey(), 'points' => 2.5, 'comment' => 'كامل'],
            ['criterion_id' => (int) $criteria[1]->getKey(), 'points' => 2, 'comment' => 'ركاكة في فقرة'],
        ],
    ])->assertOk();

    /*
    | ⚠️ 4.5, NOT 4. The column was `unsignedSmallInteger` until this spec, so a
    | rubric written in halves passed every validation and then lost them on the
    | way into the row — silently, and by exactly as much as the teacher had
    | bothered to be precise.
    */
    expect((float) $answer->refresh()->points)->toBe(4.5)
        // Full marks or it is wrong, the same rule the machine applies to a
        // partly-correct multiple-select.
        ->and($answer->is_correct)->toBeFalse()
        ->and($answer->gradingRecords()->count())->toBe(2);
});

it('records who marked it, and refuses a grader without the permission', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $assistant = $this->addWorkspaceMember($workspace);

    [, $attempt, $essay] = sitEssayExam($workspace, $student);

    $answer = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('question_id', $essay->getKey())
        ->sole();

    Sanctum::actingAs($assistant);

    $this->postJson("/api/v1/manage/grading/answers/{$answer->uuid}", [
        'marks' => [['points' => 10]],
    ])->assertForbidden();

    // FR-031 — given the permission, the same person may mark, and the mark
    // carries their name.
    $assistant->givePermissionTo(Permissions::GRADING_PERFORM);
    $assistant->givePermissionTo(Permissions::ATTEMPTS_VIEW_ALL);

    $this->postJson("/api/v1/manage/grading/answers/{$answer->uuid}", [
        'marks' => [['points' => 10]],
    ])->assertOk();

    expect((int) $answer->refresh()->graded_by)->toBe((int) $assistant->getKey());
});

it('revises a grade, keeps the old record and recomputes the total', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [, $attempt, $essay] = sitEssayExam($workspace, $student, essayPoints: 10);

    Sanctum::actingAs($owner);

    $answer = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('question_id', $essay->getKey())
        ->sole();

    $this->postJson("/api/v1/manage/grading/answers/{$answer->uuid}", [
        'marks' => [['points' => 4]],
    ])->assertOk();

    // FR-032 — a revision with no stated reason is refused before anything moves.
    $this->patchJson("/api/v1/manage/grading/answers/{$answer->uuid}", [
        'marks' => [['points' => 9]],
    ])->assertStatus(422);

    $this->patchJson("/api/v1/manage/grading/answers/{$answer->uuid}", [
        'marks' => [['points' => 9]],
        'reason' => 'أُغفلت الفقرة الأخيرة عند التصحيح الأول.',
    ])->assertOk();

    $answer->refresh();

    expect((float) $answer->points)->toBe(9.0)
        ->and((int) $answer->grading_version)->toBe(1)
        // ⚠️ BOTH GENERATIONS SURVIVE. The table is the audit; an UPDATE would
        // erase the only evidence that a grade ever moved.
        ->and($answer->gradingRecords()->count())->toBe(2)
        ->and($answer->gradingRecords()->whereNotNull('revision_of')->count())->toBe(1);

    expect(round((float) $attempt->refresh()->score))->toBe(91.0);
});

it('keeps the grading queue inside a declared query budget', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    foreach (range(1, 5) as $ignored) {
        sitEssayExam($workspace, $this->addWorkspaceMember($workspace));
    }

    Sanctum::actingAs($owner);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->getJson('/api/v1/manage/grading/queue')->assertOk();

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($response->json('meta.total'))->toBe(5)
        ->and($response->json('data.0.pending_count'))->toBe(1)
        /*
        | ⚠️ 15, AND THE NUMBER IS THE ASSERTION. Five papers is a fixture; the
        | first day of a term is 500, and a Resource runs once per row — the exam
        | title, the student's name and the count of unmarked answers asked
        | per-row is 1,500 queries on one page load. This fails the day somebody
        | drops an eager load, which is the only moment it can be caught cheaply.
        */
        ->and($queries)->toBeLessThanOrEqual(15);
});
