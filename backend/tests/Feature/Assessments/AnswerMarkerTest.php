<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Events\MistakeResolved;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\QuestionOption;
use App\Modules\Assessments\Support\AnswerMarker;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
| Spec 012 · T015. The one-answer marker both paths share.
|
| It is tested on its own, not only through `GradeAttempt`, because the whole
| reason it exists is that the adaptive path calls it ONE QUESTION AT A TIME —
| and every property that matters there (a second call for the same question, an
| event that must not fire twice) is invisible from a path that marks a whole
| paper once and never returns to it.
*/

/**
 * One exam, one question, one right option — the smallest fixture the marker
 * needs. Returns [attempt, questionId, rightOptionId, wrongOptionId].
 */
function markerFixture(object $workspace, object $student): array
{
    $exam = Exam::create([
        'workspace_id' => $workspace->getKey(),
        'uuid' => (string) Str::uuid(),
        'title' => 'اختبار التصحيح',
        'passing_score' => 50,
        'max_attempts' => 5,
        'status' => 'published',
    ]);

    $question = bankQuestion($workspace, $exam, ['content' => 'ما ناتج ١ + ١؟']);

    $right = QuestionOption::create([
        'workspace_id' => $workspace->getKey(), 'question_id' => $question->id,
        'content' => '٢', 'is_correct' => true, 'order' => 1,
    ]);
    $wrong = QuestionOption::create([
        'workspace_id' => $workspace->getKey(), 'question_id' => $question->id,
        'content' => '٣', 'is_correct' => false, 'order' => 2,
    ]);

    $attempt = app(StartAttempt::class)->handle($exam, $student);

    return [$attempt, (int) $question->id, (int) $right->id, (int) $wrong->id];
}

it('marks one answer right and one wrong against the snapshot', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    $student = $this->addWorkspaceMember($workspace, 'student');

    [$attempt, $questionId, $right, $wrong] = markerFixture($workspace, $student);
    $item = $attempt->items()->firstOrFail();

    expect(app(AnswerMarker::class)->mark($attempt, $item, [$right], null, []))->toBeTrue();

    $row = Answer::where('attempt_id', $attempt->id)->where('question_id', $questionId)->firstOrFail();

    expect((bool) $row->is_correct)->toBeTrue()
        // The raw insert boots no model, so these two are supplied by hand —
        // and a NULL uuid on MySQL is stored as '' and then collides with every
        // later row on `unique(uuid)`, silently.
        ->and($row->uuid)->not->toBeEmpty()
        ->and($row->created_at)->not->toBeNull()
        // The json cast is not applied on a raw insert either: it is encoded
        // explicitly, so reading it back must give an array and not a string.
        ->and($row->selected_option_ids)->toBe([$right]);

    // A second attempt at the same exam, answered wrongly.
    [$second, , , $secondWrong] = markerFixture($workspace, $student);

    expect(app(AnswerMarker::class)->mark($second, $second->items()->firstOrFail(), [$secondWrong], null, []))
        ->toBeFalse();
});

it('resolves a mistake only for a question the student had already got wrong', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    $student = $this->addWorkspaceMember($workspace, 'student');

    [$attempt, $questionId, $right] = markerFixture($workspace, $student);

    Event::fake([MistakeResolved::class]);

    // Not in the set: nothing to fix.
    app(AnswerMarker::class)->mark($attempt, $attempt->items()->firstOrFail(), [$right], null, []);
    Event::assertNotDispatched(MistakeResolved::class);

    // A fresh sitting of the same question, this time named as a past mistake.
    [$again, , $againRight] = markerFixture($workspace, $student);
    app(AnswerMarker::class)->mark(
        $again,
        $again->items()->firstOrFail(),
        [$againRight],
        null,
        [(int) $again->items()->firstOrFail()->question_id],
    );

    Event::assertDispatched(MistakeResolved::class, 1);
    expect($questionId)->toBeGreaterThan(0);
});

it('writes one row for two calls on the same question and refuses the second cleanly', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    $student = $this->addWorkspaceMember($workspace, 'student');

    [$attempt, $questionId, $right] = markerFixture($workspace, $student);
    $item = $attempt->items()->firstOrFail();

    app(AnswerMarker::class)->mark($attempt, $item, [$right], null, []);

    /*
     | ⚠️ A DomainException, NOT A QueryException. `unique(attempt_id,
     | question_id)` is the idempotency guard, and a constraint alone turns a
     | double tap into a 500 — the migration that added the index says so in its
     | own words. `insertOrIgnore` plus the read-back is what turns it into an
     | answer the controller can render as 422.
     */
    expect(fn () => app(AnswerMarker::class)->mark($attempt, $item, [$right], null, []))
        ->toThrow(DomainException::class);

    expect(Answer::where('attempt_id', $attempt->id)->where('question_id', $questionId)->count())->toBe(1);
});

it('does not fire a second resolution for a duplicated answer', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    $student = $this->addWorkspaceMember($workspace, 'student');

    [$attempt, $questionId, $right] = markerFixture($workspace, $student);
    $item = $attempt->items()->firstOrFail();

    Event::fake([MistakeResolved::class]);

    app(AnswerMarker::class)->mark($attempt, $item, [$right], null, [$questionId]);

    try {
        app(AnswerMarker::class)->mark($attempt, $item, [$right], null, [$questionId]);
    } catch (DomainException) {
        // The refusal is the point; what matters is what did NOT happen after it.
    }

    // Closing a mistake twice closes one somebody else's request already
    // accounted for — which is why the event stands AFTER the insert, not beside it.
    Event::assertDispatched(MistakeResolved::class, 1);
});
