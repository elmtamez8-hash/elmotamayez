<?php

declare(strict_types=1);

use App\Modules\Assessments\Events\AttemptPendingGrading;
use App\Modules\Assessments\Events\MistakeResolved;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Gamification\Listeners\AwardOnMistakeResolved;
use App\Modules\Marketplace\Events\TeacherApproved;
use App\Modules\Marketplace\Events\TeacherRejected;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Listeners\NotifyStudentGradingPending;
use App\Modules\Notifications\Listeners\NotifyTeacherApproved;
use App\Modules\Notifications\Listeners\NotifyTeacherRejected;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| The four queued listeners whose event is raised INSIDE a `DB::transaction`:
|
| - `TeacherApproved` — `ApproveTeacherApplication`, inside its transaction;
| - `TeacherRejected` — `RejectTeacherApplication`, inside its transaction;
| - `AttemptPendingGrading` and `MistakeResolved` — `GradeAttempt::grade()`,
|   which runs under `DB::transaction()` (the second through `AnswerMarker`).
|
| As plain `ShouldQueue` they were pushed the moment the event fired, so a
| transaction that then rolled back still told a teacher they were approved,
| told a student their paper was waiting for a person, and paid points for a
| mistake whose answer row was never written. `QueuedListenersAfterCommitTest`
| forbids the `ShouldQueue` + `ShouldHandleEventsAfterCommit` pairing; it cannot
| see a bare `ShouldQueue` whose event happens to be raised inside a transaction,
| so each of these is measured here through the real provider wiring.
|
| ⚠️ ON THE `sync` CONNECTION, AND NEVER WITH `Queue::fake()` (see
| `QueuedListenersAfterCommitTest`). And the assertion is on the JOB, not on a
| row: on `sync` a listener that ran too early wrote inside the same transaction
| and was rolled back with it, so «no row» is true of both builds.
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
});

dataset('events raised inside a transaction', [
    'TeacherApproved' => [
        fn (): object => new TeacherApproved(TeacherProfile::factory()->create([
            'workspace_id' => test()->createWorkspaceWithOwner()[0]->getKey(),
        ])),
        NotifyTeacherApproved::class,
    ],
    'TeacherRejected' => [
        fn (): object => new TeacherRejected(TeacherApplication::factory()->create([
            'workspace_id' => test()->createWorkspaceWithOwner()[0]->getKey(),
        ]), 'reason'),
        NotifyTeacherRejected::class,
    ],
    'AttemptPendingGrading' => [
        fn (): object => new AttemptPendingGrading((new Attempt)->forceFill(['id' => 987654])),
        NotifyStudentGradingPending::class,
    ],
    'MistakeResolved' => [
        fn (): object => new MistakeResolved(987654, 987654, 987654),
        AwardOnMistakeResolved::class,
    ],
]);

it('queues nothing for a transaction that rolled back', function (object $event, string $listener): void {
    // Pest resolves each closure above while binding the dataset, with the
    // test case as `test()`.

    /** @var list<string> $ran */
    $ran = [];
    Event::listen(JobProcessing::class, function (JobProcessing $processing) use (&$ran): void {
        $ran[] = $processing->job->resolveName();
    });

    try {
        DB::transaction(function () use ($event): void {
            event($event);

            throw new RuntimeException('roll back');
        });
    } catch (Throwable) {
        // The rollback is the point; whatever threw, nothing may have run.
    }

    expect($ran)->not->toContain($listener);
})->with('events raised inside a transaction');
