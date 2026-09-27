<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\RequestPrivateSession;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| The pending-request ceiling (student × teacher) under overlapping submits.
|
| ⛔ It was `INSERT … SELECT … WHERE (SELECT COUNT(*) …) < ?`, documented as
| reading the number and writing the row «under one lock». There was no row to
| lock: on MySQL under REPEATABLE READ a script's parallel submits could all
| read the count below the ceiling (or deadlock into a 500). The fix contends on
| the teacher's profile row as the transaction's first statement, and counts
| only after it.
|
| ⚠️ A SEQUENTIAL «submit four times» TEST IS GREEN AGAINST THE BROKEN BUILD, and
| SQLite's single in-memory connection cannot reproduce the MySQL interleaving.
| What is pinned is the mechanism the MySQL guarantee rests on — gate inside
| the transaction, count after it, a rival committed during the wait is
| counted, a deadlock is «حاول مرة أخرى» — exactly as `FreezeCeilingRaceTest`
| does for the freeze ceiling.
*/

function privateRaceIsGate(string $sql): bool
{
    return str_starts_with($sql, 'UPDATE teacher_profiles SET id = id');
}

function privateRaceSubmit(array $fx): PrivateSessionRequest
{
    return app(RequestPrivateSession::class)->handle($fx['course'], $fx['student'], $fx['startsAt']);
}

it('takes the teacher gate inside the transaction, then counts, then writes', function (): void {
    $fx = privateSessionFixture();

    $baseLevel = DB::transactionLevel();
    $log = [];

    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $log[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
    });

    privateRaceSubmit($fx);

    $index = static function (callable $match) use ($log): ?int {
        foreach ($log as $i => $entry) {
            if ($match($entry['sql'])) {
                return $i;
            }
        }

        return null;
    };

    $gate = $index(privateRaceIsGate(...));
    $count = $index(fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'count(') && str_contains($sql, '"private_session_requests"'));
    $insert = $index(fn (string $sql): bool => str_starts_with($sql, 'insert into "private_session_requests"'));

    expect($gate)->not->toBeNull('no gate statement — the count has no row to contend on')
        ->and($count)->not->toBeNull()
        ->and($insert)->not->toBeNull()
        ->and($gate)->toBeLessThan($count)
        ->and($count)->toBeLessThan($insert)
        ->and($log[$gate]['level'])->toBeGreaterThan($baseLevel)
        ->and($log[$insert]['level'])->toBe($log[$gate]['level']);
});

it('counts rival requests committed while this one waited on the gate', function (): void {
    $fx = privateSessionFixture();
    $fired = false;

    DB::listen(function (QueryExecuted $query) use (&$fired, $fx): void {
        if ($fired || ! privateRaceIsGate($query->sql)) {
            return;
        }

        $fired = true;

        // Three parallel submits from the same script, each at its own hour,
        // committed while this one waited on the teacher's row.
        foreach ([1, 2, 3] as $hours) {
            DB::table('private_session_requests')->insert([
                'workspace_id' => $fx['course']->workspace_id,
                'uuid' => (string) Str::uuid(),
                'course_id' => $fx['course']->getKey(),
                'student_user_id' => $fx['student']->getKey(),
                'teacher_profile_id' => $fx['profile']->getKey(),
                'starts_at' => $fx['startsAt']->addHours($hours)->utc()->format('Y-m-d H:i:s'),
                'duration_minutes' => 45,
                'status' => PrivateSessionRequest::PENDING,
                'expires_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'pending_slot' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    expect(fn () => privateRaceSubmit($fx))
        ->toThrow(DomainException::class, 'لديك ٣ طلبات تنتظر الردّ عند هذا المدرّس. انتظر الردّ أو اسحب أحدها.');

    expect($fired)->toBeTrue('the gate never ran — the test proved nothing');

    // The loser's own row is not there. The rivals' rows are not asserted: on
    // this one connection they roll back with the loser's transaction.
    expect(PrivateSessionRequest::query()->withoutWorkspaceScope()
        ->where('starts_at', $fx['startsAt']->utc()->format('Y-m-d H:i:s'))
        ->exists())->toBeFalse();
});

it('answers a deadlock with «try again», never a 500 and never the ceiling', function (): void {
    $fx = privateSessionFixture();

    DB::beforeExecuting(function (string $sql): void {
        if (privateRaceIsGate($sql)) {
            throw new QueryException('sqlite', $sql, [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        }
    });

    expect(fn () => privateRaceSubmit($fx))
        ->toThrow(DomainException::class, 'تعذّر تسجيل الطلب. حاول مرة أخرى.');

    expect(PrivateSessionRequest::query()->withoutWorkspaceScope()->count())->toBe(0);
});
