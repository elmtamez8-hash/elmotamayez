<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| ⛔ The attempt allowance was a count followed by an insert.
|
| A double tap on «ابدأ» put two workers through `guardAttemptLimit()` together:
| with `max_attempts = 1` both counted zero and both inserted — two graded
| attempts at a paper allowed one. The claim is now
| `unique(exam_id, student_user_id, attempt_number)`.
|
| ⚠️ A SEQUENTIAL «start it twice» TEST PASSES AGAINST A BUILD WITH NO CLAIM IN
| IT — the second start counts the first one and refuses a line earlier. The
| window is between the count and the insert, and it is opened single-threaded
| with a query listener on the count itself: the competing insert written the
| instant that count has answered is the other tap winning inside the window. No
| threads, no sleeps.
|
| ⚠️ NOT A `creating` HOOK. That fires inside the start's own transaction, so
| the competitor's row is rolled back with the loser and the test asserts about
| a tap that never happened.
*/

function attemptRaceExam(Workspace $workspace, int $maxAttempts): Exam
{
    $exam = Exam::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => 'published',
        'max_attempts' => $maxAttempts,
    ]);
    bankQuestion($workspace, $exam);

    return $exam;
}

/** The other tap, landing between this start's count and its insert. */
function attemptRaceCompetitor(Exam $exam, User $student): void
{
    $fired = false;

    DB::listen(function (QueryExecuted $query) use (&$fired, $exam, $student): void {
        if ($fired || ! str_contains($query->sql, 'max(attempt_number)')) {
            return;
        }

        $fired = true;

        DB::table('exam_attempts')->insert([
            'workspace_id' => $exam->workspace_id,
            'uuid' => (string) Str::uuid(),
            'exam_id' => $exam->id,
            'student_user_id' => $student->id,
            'status' => 'in_progress',
            'is_practice' => false,
            'attempt_number' => 1,
            'random_seed' => 1,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

it('gives a double tap exactly one attempt at a one-attempt exam', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, 'student');
    $exam = attemptRaceExam($workspace, maxAttempts: 1);

    attemptRaceCompetitor($exam, $student);

    expect(fn () => app(StartAttempt::class)->handle($exam, $student))
        ->toThrow(DomainException::class, 'بدأتَ محاولةً لهذا الاختبار للتوّ');

    // One row — the competitor's. The loser left nothing behind, frozen items
    // included, because the claim is the first statement of its transaction.
    expect(Attempt::query()->withoutWorkspaceScope()->where('exam_id', $exam->id)->count())->toBe(1)
        ->and(DB::table('attempt_items')->count())->toBe(0);
});

it('numbers official attempts in order and leaves practice runs unnumbered', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, 'student');
    $exam = attemptRaceExam($workspace, maxAttempts: 2);

    $first = app(StartAttempt::class)->handle($exam, $student);
    $practice = app(StartAttempt::class)->handle($exam, $student, isPractice: true);
    $second = app(StartAttempt::class)->handle($exam, $student);

    expect($first->attempt_number)->toBe(1)
        ->and($practice->attempt_number)->toBeNull()
        ->and($second->attempt_number)->toBe(2);

    expect(fn () => app(StartAttempt::class)->handle($exam, $student))
        ->toThrow(DomainException::class, 'استنفدتَ');

    // A practice run spends no official attempt, so it is never refused by the
    // allowance and never collides with another practice run.
    app(StartAttempt::class)->handle($exam, $student, isPractice: true);
});

it('does not collide with a number already taken when an earlier attempt was deleted', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, 'student');
    $exam = attemptRaceExam($workspace, maxAttempts: 3);

    $first = app(StartAttempt::class)->handle($exam, $student);
    app(StartAttempt::class)->handle($exam, $student);

    // Erasure or a reset removes #1; `count + 1` would ask for #2 again and be
    // refused for ever with attempts left.
    DB::table('exam_attempts')->where('id', $first->id)->delete();

    expect(app(StartAttempt::class)->handle($exam, $student)->attempt_number)->toBe(3);
});
