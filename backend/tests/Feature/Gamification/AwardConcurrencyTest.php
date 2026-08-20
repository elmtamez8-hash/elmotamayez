<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\GamificationAction;
use Illuminate\Support\Facades\DB;

/**
 * The daily cap holds when two awards overlap (FR-006 · SC-004).
 *
 * ⚠️ FIRING THE SAME EVENT TWICE IN A ROW PROVES THE UNIQUE INDEX, NOT THE RACE —
 * and for the cap it proves nothing at all, because a naive count-then-write
 * implementation also lands exactly on the limit when the calls are sequential.
 * What separates the two is INTERLEAVING: one runner reading the count, another
 * filling the allowance, and the first writing anyway.
 *
 * So the window is opened deliberately, the way spec 017 reproduces its own claim
 * race: a query hook fires INSIDE the outer award, between the counter row being
 * created and the allowance being claimed. No threads, no sleeps, and it fails
 * against a read-then-write implementation.
 */
beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();
});

it('refuses the claim when the allowance is spent inside the window', function (): void {
    $cap = (int) GamificationAction::query()->where('key', 'session_attended')->sole()->daily_cap;

    $studentId = (int) $this->student->getKey();
    $workspaceId = (int) $this->workspace->getKey();

    $award = fn (int $sourceId) => app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: $studentId,
        actionKey: 'session_attended',
        sourceType: 'race',
        sourceId: $sourceId,
        workspaceId: $workspaceId,
    ));

    $fired = false;

    DB::listen(function ($query) use (&$fired, $award, $cap): void {
        if ($fired || ! str_contains($query->sql, 'award_daily_counters')) {
            return;
        }

        if (stripos(trim($query->sql), 'insert') !== 0) {
            return;
        }

        // The other runner wins the whole allowance inside this exact window.
        $fired = true;

        foreach (range(100, 99 + $cap) as $n) {
            $award($n);
        }
    });

    $outer = $award(1);

    /*
    | The outer award entered before the allowance existed and finds it gone.
    |
    | A count-then-write implementation would have read "0 < cap" before the hook
    | ran and would write anyway — cap + 1 awards for a cap of `cap`, and a
    | student who found the ceiling porous under load.
    */
    expect($outer)->toBeNull()
        ->and(AwardEntry::query()->count())->toBe($cap);
});

/*
 * And the balance never goes below zero when two deductions overlap.
 *
 * Same idiom, opposite direction: the purse is emptied inside the window, so the
 * outer deduction finds nothing to take. The guard is in the WHERE, so it simply
 * matches no rows — and the Action rolls its whole transaction back rather than
 * leaving an entry that says a number the aggregate does not hold.
 */
it('never writes a negative balance when a deduction overlaps another', function (): void {
    $studentId = (int) $this->student->getKey();
    $workspaceId = (int) $this->workspace->getKey();

    // 20 experience to take from.
    foreach ([1, 2] as $n) {
        app(AwardPoints::class)->handle(new AwardRequest(
            studentUserId: $studentId,
            actionKey: 'session_attended',
            sourceType: 'setup',
            sourceId: $n,
            workspaceId: $workspaceId,
        ));
    }

    $fired = false;

    /*
    | ⚠️ THE WINDOW IS BETWEEN THE CLAMP AND THE MOVE, and picking the wrong one
    | makes this test assert the opposite of what it claims. Hook the counter and
    | the balance is already zero when the clamp reads it — so the Action
    | correctly records a deduction of ZERO and everything succeeds, which is
    | right, and proves nothing about the guard. The entry insert is the moment
    | the amount has been decided and not yet applied.
    */
    DB::listen(function ($query) use (&$fired, $studentId): void {
        if ($fired || ! str_contains($query->sql, 'award_entries')) {
            return;
        }

        if (stripos(trim($query->sql), 'insert') !== 0) {
            return;
        }

        $fired = true;

        // The other runner takes everything first.
        DB::table('student_progress')->where('user_id', $studentId)->update(['xp' => 0]);
    });

    $threw = false;

    try {
        app(AwardPoints::class)->handle(new AwardRequest(
            studentUserId: $studentId,
            actionKey: 'payment_overdue',
            sourceType: 'billing',
            sourceId: 1,
            workspaceId: $workspaceId,
        ));
    } catch (RuntimeException) {
        // Expected: the deduction it had already written into the entry can no
        // longer be applied, so the whole transaction rolls back rather than
        // leaving a row that claims a number the aggregate does not hold.
        $threw = true;
    }

    /*
    | ⚠️ THE INTERFERENCE ROLLS BACK TOO, and the assertion has to say so honestly.
    | One connection means the hook's write is inside the Action's own
    | transaction, so undoing the award undoes the simulated other runner with it.
    | What this proves is the part that matters: the guard REFUSED (the WHERE
    | matched nothing) and the rollback was clean. It does not — and cannot, on one
    | connection — prove what two real workers leave behind.
    */
    expect($threw)->toBeTrue()
        ->and(AwardEntry::query()->where('action_key', 'payment_overdue')->count())->toBe(0)
        ->and((int) DB::table('student_progress')->where('user_id', $studentId)->value('xp'))->toBe(20);
});
