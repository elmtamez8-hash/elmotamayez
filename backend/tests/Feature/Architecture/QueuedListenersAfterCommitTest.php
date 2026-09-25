<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Finder\Finder;

/*
| ⛔ A QUEUED LISTENER WAITS FOR THE COMMIT ONLY IF IT IS `ShouldQueueAfterCommit`.
|
| `ShouldHandleEventsAfterCommit` reads as if it said the same thing and does
| not, for a queued listener: `Illuminate\Events\Dispatcher::createClassCallable()`
| returns the queued callable for anything implementing `ShouldQueue` BEFORE it
| ever asks `handlerShouldBeDispatchedAfterDatabaseTransactions()` — the one
| place that marker is read. What decides a queued listener's timing is
| `propagateListenerOptions()`, which sets `$job->afterCommit` from
| `ShouldQueueAfterCommit` (or a public `$afterCommit` property), and nothing
| else. With `after_commit => false` on every connection in `config/queue.php`,
| thirty-seven listeners carrying the wrong pairing were pushed to Redis INSIDE
| the transaction that dispatched their event: a worker could read the rows
| before they were committed — an order still `pending`, a register not yet
| written — or run for a transaction that then rolled back.
|
| `ShouldHandleEventsAfterCommit` is correct on a SYNC listener, where that
| branch is actually reached; the guard below forbids only the pairing.
*/

/**
 * Whether a class declaration (comments already removed) implements both
 * `ShouldQueue` and `ShouldHandleEventsAfterCommit`.
 */
function queuedAfterCommitPairing(string $code): bool
{
    if (preg_match_all('/\bclass\s+\w+[^{;]*?\bimplements\b([^{]+)\{/', $code, $m) === 0) {
        return false;
    }

    foreach ($m[1] as $clause) {
        $names = array_map(
            static fn (string $n): string => ltrim((string) strrchr('\\'.trim($n), '\\'), '\\'),
            explode(',', $clause),
        );

        if (in_array('ShouldQueue', $names, true) && in_array('ShouldHandleEventsAfterCommit', $names, true)) {
            return true;
        }
    }

    return false;
}

it('recognises the forbidden pairing and nothing else', function (): void {
    /*
    | The positive control. A regex that matches nothing passes the scan below
    | over any tree whatsoever.
    */
    expect(queuedAfterCommitPairing('final class A implements ShouldHandleEventsAfterCommit, ShouldQueue {'))->toBeTrue()
        ->and(queuedAfterCommitPairing("class A implements \\Illuminate\\Contracts\\Queue\\ShouldQueue,\n    ShouldHandleEventsAfterCommit\n{"))->toBeTrue()
        ->and(queuedAfterCommitPairing('class A implements ShouldQueueAfterCommit {'))->toBeFalse()
        ->and(queuedAfterCommitPairing('class A implements ShouldHandleEventsAfterCommit {'))->toBeFalse()
        ->and(queuedAfterCommitPairing('class A implements ShouldQueue {'))->toBeFalse();
});

it('finds no class under app/ that is queued and marked ShouldHandleEventsAfterCommit', function (): void {
    $examined = 0;
    $queuedAfterCommit = 0;
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $code = codeWithoutComments((string) $file->getContents());
        $examined++;

        if (preg_match('/\bimplements\b[^{]*\bShouldQueueAfterCommit\b/', $code) === 1) {
            $queuedAfterCommit++;
        }

        if (queuedAfterCommitPairing($code)) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    /*
    | The sanity half: a Finder that matched nothing, or a pattern that no
    | longer reads the tree, would pass the last assertion for ever.
    */
    expect($examined)->toBeGreaterThan(0)
        ->and($queuedAfterCommit)->toBeGreaterThan(0)
        ->and($offenders)->toBe([]);
});

/*
| The behaviour itself, measured on the `sync` connection the suite runs on.
|
| ⚠️ NOT WITH `Queue::fake()`: `QueueFake::push()` records the job at once and
| never consults `afterCommit`, so a faked queue cannot tell the two pairings
| apart. `SyncQueue` goes through the same `Queue::enqueueUsing()` →
| `shouldDispatchAfterCommit()` gate a Redis queue does, so it runs the listener
| exactly when a Redis push would happen. And under `RefreshDatabase` the
| testing `DatabaseTransactionsManager` leaves the wrapping test transaction out
| of `callbackApplicableTransactions()`, so the `DB::transaction()` below is the
| one whose commit is awaited — as in production.
*/
final class QueuedAfterCommitProbeEvent
{
    public function __construct(public string $marker) {}
}

final class QueuedAfterCommitProbeListener implements ShouldQueueAfterCommit
{
    /** @var list<string> */
    public static array $ran = [];

    public function handle(QueuedAfterCommitProbeEvent $event): void
    {
        self::$ran[] = $event->marker;
    }
}

final class QueuedWrongPairingProbeListener implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    /** @var list<string> */
    public static array $ran = [];

    public function handle(QueuedAfterCommitProbeEvent $event): void
    {
        self::$ran[] = $event->marker;
    }
}

beforeEach(function (): void {
    QueuedAfterCommitProbeListener::$ran = [];
    QueuedWrongPairingProbeListener::$ran = [];
    config(['queue.default' => 'sync']);
});

it('runs a ShouldQueueAfterCommit listener only once the transaction commits', function (): void {
    Event::listen(QueuedAfterCommitProbeEvent::class, QueuedAfterCommitProbeListener::class);

    $insideTransaction = null;

    DB::transaction(function () use (&$insideTransaction): void {
        event(new QueuedAfterCommitProbeEvent('committed'));
        $insideTransaction = QueuedAfterCommitProbeListener::$ran;
    });

    expect($insideTransaction)->toBe([])
        ->and(QueuedAfterCommitProbeListener::$ran)->toBe(['committed']);
});

it('never runs a ShouldQueueAfterCommit listener for a transaction that rolled back', function (): void {
    Event::listen(QueuedAfterCommitProbeEvent::class, QueuedAfterCommitProbeListener::class);

    try {
        DB::transaction(function (): void {
            event(new QueuedAfterCommitProbeEvent('rolled-back'));

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(QueuedAfterCommitProbeListener::$ran)->toBe([]);
});

it('shows the old pairing ran inside the transaction, which is the defect', function (): void {
    /*
    | Kept so the reason for the guard is measured rather than asserted: the day
    | the framework starts honouring `ShouldHandleEventsAfterCommit` on the
    | queued path this case fails, and the guard above can be reconsidered.
    */
    Event::listen(QueuedAfterCommitProbeEvent::class, QueuedWrongPairingProbeListener::class);

    $insideTransaction = null;

    DB::transaction(function () use (&$insideTransaction): void {
        event(new QueuedAfterCommitProbeEvent('too-early'));
        $insideTransaction = QueuedWrongPairingProbeListener::$ran;
    });

    expect($insideTransaction)->toBe(['too-early']);
});
