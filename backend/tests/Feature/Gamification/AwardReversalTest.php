<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Actions\ReverseAward;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\CoinBalance;
use App\Modules\Gamification\Models\StudentProgress;

/**
 * Reversing an award actually returns the points (FR-010 · SC-024).
 *
 * ⚠️ EVERY ASSERTION HERE COMPARES THE AGGREGATE, NEVER THE ROW COUNT — and that
 * is the whole point of the file.
 *
 * The design under review put the reversal in the same idempotency key as the
 * entry it reverses. Under it, `insertOrIgnore` writes ZERO rows, the read-back
 * finds the ORIGINAL, the code concludes "already recorded" and returns success
 * — and the points are never returned, for ever. A test that counted rows and
 * expected two would have caught it; a test that counted rows and expected "at
 * least one" would not; and a test that asserted the Action returned without
 * throwing would have certified the broken design as working.
 */
beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();
});

function progressXp(User $student): int
{
    return (int) (StudentProgress::query()->where('user_id', $student->getKey())->value('xp') ?? 0);
}

function purse(User $student, int $workspaceId): int
{
    return (int) (CoinBalance::query()
        ->withoutWorkspaceScope()
        ->where('user_id', $student->getKey())
        ->where('workspace_id', $workspaceId)
        ->value('coins') ?? 0);
}

it('returns the aggregate to exactly what it was before the award', function (): void {
    $workspaceId = (int) $this->workspace->getKey();

    $before = progressXp($this->student);
    $coinsBefore = purse($this->student, $workspaceId);

    $entry = app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $this->student->getKey(),
        actionKey: 'session_attended',
        sourceType: 'class_session',
        sourceId: 5,
        workspaceId: $workspaceId,
    ));

    expect($entry)->not->toBeNull()
        ->and(progressXp($this->student))->toBeGreaterThan($before);

    app(ReverseAward::class)->handle($entry);

    expect(progressXp($this->student))->toBe($before)
        ->and(purse($this->student, $workspaceId))->toBe($coinsBefore)
        // The history is kept: two entries, summing to zero. A correction that
        // deleted the original would leave the student's screen and the teacher's
        // memory permanently disagreeing about what was earned.
        ->and(AwardEntry::query()->count())->toBe(2)
        ->and((int) AwardEntry::query()->sum('xp'))->toBe(0)
        ->and((int) AwardEntry::query()->sum('coins'))->toBe(0);
});

/*
 * The reversal must not collide with its own original.
 *
 * ⚠️ THIS IS THE ASSERTION THE BROKEN DESIGN FAILED. Everything about the two
 * rows is identical — student, action, source_type, source_id — so `reversal_of_id`
 * is the only thing separating them in the unique index.
 */
it('writes a distinct row that points back at the original', function (): void {
    $entry = app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $this->student->getKey(),
        actionKey: 'session_attended',
        sourceType: 'class_session',
        sourceId: 5,
        workspaceId: (int) $this->workspace->getKey(),
    ));

    $reversal = app(ReverseAward::class)->handle($entry);

    expect($reversal)->not->toBeNull()
        ->and($reversal->getKey())->not->toBe($entry->getKey())
        ->and($reversal->reversal_of_id)->toBe((int) $entry->getKey())
        ->and($entry->reversal_of_id)->toBe(0)
        ->and($reversal->xp)->toBe(-$entry->xp)
        // Carried, not recomputed: the rebuild has to reproduce the ranking that
        // was actually shown (SC-011).
        ->and($reversal->level_band)->toBe($entry->level_band);
});

it('reverses only once, however many times it is asked', function (): void {
    $entry = app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $this->student->getKey(),
        actionKey: 'session_attended',
        sourceType: 'class_session',
        sourceId: 5,
        workspaceId: (int) $this->workspace->getKey(),
    ));

    $before = progressXp($this->student);

    app(ReverseAward::class)->handle($entry);
    $second = app(ReverseAward::class)->handle($entry);

    expect($second)->toBeNull()
        ->and(AwardEntry::query()->count())->toBe(2)
        // Not `$before - xp - xp`: a second reversal would take the points twice.
        ->and(progressXp($this->student))->toBe($before - $entry->xp);
});

it('refuses to reverse a reversal', function (): void {
    $entry = app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $this->student->getKey(),
        actionKey: 'session_attended',
        sourceType: 'class_session',
        sourceId: 5,
        workspaceId: (int) $this->workspace->getKey(),
    ));

    $reversal = app(ReverseAward::class)->handle($entry);

    expect(fn () => app(ReverseAward::class)->handle($reversal))->toThrow(RuntimeException::class);
});

/*
 * A reversal cannot take more than the student has.
 *
 * The student spent the coins before the correction arrived, so returning the
 * full amount would put the purse below zero — and the guard is in the WHERE, so
 * the statement would simply refuse and the Action would roll back, leaving the
 * award standing for a session everyone agrees did not happen. What it must do
 * instead is take what is there and RECORD THAT: the entry says what was applied,
 * which is the same rule that keeps FR-005 and FR-009 from contradicting each
 * other on a penalty larger than the balance.
 */
it('records what it could actually take back', function (): void {
    $workspaceId = (int) $this->workspace->getKey();

    $entry = app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $this->student->getKey(),
        actionKey: 'session_attended',
        sourceType: 'class_session',
        sourceId: 5,
        workspaceId: $workspaceId,
    ));

    // The student spends. (The shop does this properly in US4; here it is the
    // state that matters, not the route to it.)
    CoinBalance::query()->withoutWorkspaceScope()
        ->where('user_id', $this->student->getKey())
        ->where('workspace_id', $workspaceId)
        ->update(['coins' => 2]);

    $reversal = app(ReverseAward::class)->handle($entry);

    expect($reversal->coins)->toBe(-2)
        ->and(purse($this->student, $workspaceId))->toBe(0)
        // The entry records what was applied, not the nominal amount.
        ->and($reversal->coins)->not->toBe(-$entry->coins);
});
