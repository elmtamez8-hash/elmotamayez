<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Actions\SyncExamItems;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\Question;
use Illuminate\Support\Str;

/*
| SC-001. One question, three exams, one row.
|
| ⚠️ COUNTING THE EXAMS IS NOT THE ASSERTION. Three exams each holding their own
| copy would also give three exams a question — and that is precisely the old
| shape, where `questions.exam_id` made a copy per exam the only thing possible.
| What proves the feature is that `questions` still holds ONE row while
| `exam_items` holds three, and that editing the one changes all three.
*/

function reuseExam(int $workspaceId, string $title): Exam
{
    return Exam::create([
        'workspace_id' => $workspaceId, 'uuid' => Str::uuid(),
        'title' => $title, 'passing_score' => 50, 'max_attempts' => 3, 'status' => 'published',
    ]);
}

it('puts one bank question into three exams as a single row', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $question = bankQuestion($workspace, null, ['content' => 'ما قانون نيوتن الثاني؟']);
    $sync = app(SyncExamItems::class);

    $exams = collect(['الشهري', 'النصفي', 'النهائي'])
        ->map(fn (string $title) => reuseExam((int) $workspace->id, $title));

    foreach ($exams as $exam) {
        $sync->handle($exam, [['uuid' => $question->uuid]]);
    }

    expect(Question::where('content', 'ما قانون نيوتن الثاني؟')->count())->toBe(1)
        ->and(ExamItem::where('question_id', $question->id)->count())->toBe(3);

    // And editing the bank row reaches all three, because there is nothing else
    // to reach.
    $question->update(['content' => 'صِغ قانون نيوتن الثاني.']);

    foreach ($exams as $exam) {
        expect($exam->questions()->first()->content)->toBe('صِغ قانون نيوتن الثاني.');
    }
});

it('lets one exam value a question differently without moving the bank row', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $question = bankQuestion($workspace, null, ['content' => 'سؤالٌ يستحقّ أكثر في النهائي؟']);
    $question->update(['points' => 1]);

    $quiz = reuseExam((int) $workspace->id, 'اختبار قصير');
    $final = reuseExam((int) $workspace->id, 'النهائي');

    $sync = app(SyncExamItems::class);
    $sync->handle($quiz, [['uuid' => $question->uuid]]);
    $sync->handle($final, [['uuid' => $question->uuid, 'points_override' => 5]]);

    expect(ExamItem::where('exam_id', $final->id)->firstOrFail()->effectivePoints())->toBe(5)
        // Null override means "whatever the bank says" — so raising the bank's
        // default raises it everywhere the teacher did not say otherwise.
        ->and(ExamItem::where('exam_id', $quiz->id)->firstOrFail()->effectivePoints())->toBe(1)
        ->and($question->fresh()->points)->toBe(1);
});

it('sets an exam to exactly the list it is given', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $a = bankQuestion($workspace, null, ['content' => 'أ؟']);
    $b = bankQuestion($workspace, null, ['content' => 'ب؟']);
    $c = bankQuestion($workspace, null, ['content' => 'ج؟']);

    $exam = reuseExam((int) $workspace->id, 'اختبار الترتيب');
    $sync = app(SyncExamItems::class);

    $sync->handle($exam, [['uuid' => $a->uuid], ['uuid' => $b->uuid], ['uuid' => $c->uuid]]);

    // ⚠️ THE SECOND CALL DROPS WHAT IT DOES NOT MENTION, and that is the half a
    // partial edit cannot express. It is also why the client sends the whole
    // list: an add and a remove are two operations against a state that may have
    // moved between them.
    $sync->handle($exam, [['uuid' => $c->uuid], ['uuid' => $a->uuid]]);

    expect($exam->items()->pluck('question_id')->all())->toBe([$c->id, $a->id])
        ->and(ExamItem::where('exam_id', $exam->id)->count())->toBe(2)
        // Dropped from the exam, still in the bank. All three.
        ->and(Question::whereIn('id', [$a->id, $b->id, $c->id])->count())->toBe(3);
});

it('refuses to change the questions of an exam that has been sat', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace, 'student');
    $exam = reuseExam((int) $workspace->id, 'اختبارٌ جلس عليه أحد');
    $question = bankQuestion($workspace, $exam, ['content' => 'سؤال؟']);

    app(StartAttempt::class)->handle($exam, $student);

    expect(fn () => app(SyncExamItems::class)->handle($exam, []))
        ->toThrow(DomainException::class);

    expect(ExamItem::where('exam_id', $exam->id)->count())->toBe(1)
        ->and($question->fresh())->not->toBeNull();
});

it('does not let a practice run freeze an exam', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace, 'student');
    $exam = reuseExam((int) $workspace->id, 'اختبارٌ للمراجعة');
    bankQuestion($workspace, $exam, ['content' => 'سؤال؟']);
    $extra = bankQuestion($workspace, null, ['content' => 'سؤالٌ يُضاف؟']);

    // A revision sitting consumes no attempt and carries no result (FR-026أ), so
    // letting one lock the exam would hand any student the power to freeze their
    // teacher out of it.
    app(StartAttempt::class)
        ->handle($exam, $student, null, isPractice: true);

    app(SyncExamItems::class)->handle($exam, [['uuid' => $extra->uuid]]);

    expect($exam->items()->pluck('question_id')->all())->toBe([$extra->id]);
});
