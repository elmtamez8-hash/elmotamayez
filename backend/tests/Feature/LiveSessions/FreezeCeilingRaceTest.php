<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
use App\Modules\LiveSessions\Models\FreezePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
| The monthly freeze ceiling under two overlapping «تجميد الفترة» presses.
|
| ⛔ It was `INSERT … SELECT … WHERE (SELECT COUNT(*) …) < ?` and called atomic.
| On MySQL under REPEATABLE READ two requests could both read the count below
| the ceiling (or deadlock into a 500): a count of rows that do not exist yet has
| no row to contend on. The fix contends on one that does — the workspace row —
| as the transaction's first statement, and counts only after it.
|
| ⚠️ A SEQUENTIAL «freeze three times» TEST IS GREEN AGAINST THE BROKEN BUILD —
| `FreezeLimitsTest` was, for exactly that reason. And SQLite cannot reproduce
| the MySQL interleaving at all: this suite has ONE in-memory connection. So
| what is pinned here is the mechanism the MySQL guarantee rests on:
|
|   1. the gate exists and runs INSIDE the claim's transaction;
|   2. the count runs AFTER the gate, so a rival that committed while this
|      request waited on the gate is counted — the rival is written the
|      instant the gate statement has run, no threads, no sleeps;
|   3. a deadlock is answered as «حاول مرة أخرى», never a 500 and never the
|      ceiling.
|
| Against the old build the first two cases fail on `$fired` / the gate index
| (there was no gate), and against a build that counts BEFORE the gate the
| second one fails on the missing refusal.
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->month = CarbonImmutable::now()->addMonths(2)->startOfMonth();
});

function freezeRaceCreate(CarbonImmutable $from, CarbonImmutable $to): FreezePeriod
{
    return app(CreateFreezePeriod::class)->handle(test()->owner, $from, $to, null, 'إجازة')['period'];
}

function freezeRaceIsGate(string $sql): bool
{
    return str_starts_with($sql, 'UPDATE workspaces SET id = id');
}

it('takes the workspace gate inside the transaction, then counts, then writes', function (): void {
    $baseLevel = DB::transactionLevel();
    $log = [];

    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $log[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
    });

    freezeRaceCreate($this->month->addDays(1), $this->month->addDays(2));

    $index = static function (callable $match) use ($log): ?int {
        foreach ($log as $i => $entry) {
            if ($match($entry['sql'])) {
                return $i;
            }
        }

        return null;
    };

    $gate = $index(freezeRaceIsGate(...));
    $count = $index(fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'count(') && str_contains($sql, '"freeze_period_starts"'));
    $insert = $index(fn (string $sql): bool => str_starts_with($sql, 'insert into "freeze_period_starts"'));

    expect($gate)->not->toBeNull('no gate statement — the count has no row to contend on')
        ->and($count)->not->toBeNull()
        ->and($insert)->not->toBeNull()
        ->and($gate)->toBeLessThan($count)
        ->and($count)->toBeLessThan($insert)
        // Inside the claim's own transaction, or the lock is released before
        // the count and serialises nothing.
        ->and($log[$gate]['level'])->toBeGreaterThan($baseLevel)
        ->and($log[$insert]['level'])->toBe($log[$gate]['level']);
});

it('counts a rival that filled the month while this request waited on the gate', function (): void {
    $fired = false;

    DB::listen(function (QueryExecuted $query) use (&$fired): void {
        if ($fired || ! freezeRaceIsGate($query->sql)) {
            return;
        }

        $fired = true;

        // The other press, committed while this one waited on the workspace row.
        foreach ([3, 5] as $day) {
            DB::table('freeze_period_starts')->insert([
                'workspace_id' => test()->workspace->getKey(),
                'student_user_id' => null,
                'starts_on' => test()->month->addDays($day)->toDateString(),
                'created_at' => now(),
            ]);
        }
    });

    expect(fn () => freezeRaceCreate($this->month->addDays(10), $this->month->addDays(11)))
        ->toThrow(DomainException::class, 'لا تبدأ في الشهر الواحد أكثر من فترتي تجميد — والفترة التي رُفعت تُحسب أيضاً. اختر بداية في شهر آخر.');

    expect($fired)->toBeTrue('the gate never ran — the test proved nothing');

    // The loser wrote nothing. The rival's ledger rows are deliberately not
    // asserted: on this one connection they sit inside the loser's transaction
    // and roll back with it, which says nothing about two real workers.
    expect(FreezePeriod::query()->count())->toBe(0);
});

it('answers a deadlock with «try again», never a 500 and never the ceiling', function (): void {
    DB::beforeExecuting(function (string $sql): void {
        if (freezeRaceIsGate($sql)) {
            throw new QueryException('sqlite', $sql, [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        }
    });

    expect(fn () => freezeRaceCreate($this->month->addDays(1), $this->month->addDays(2)))
        ->toThrow(DomainException::class, 'تعذّر تسجيل فترة التجميد الآن. حاول مرة أخرى.');

    expect(FreezePeriod::query()->count())->toBe(0)
        ->and(DB::table('freeze_period_starts')->count())->toBe(0);
});
