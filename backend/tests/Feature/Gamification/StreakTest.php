<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\LiveSessions\Models\FreezePeriod;
use Carbon\CarbonImmutable;

/**
 * The streak grows, breaks, survives a freeze, and is saved by a shield
 * (FR-013 … FR-016 · SC-006).
 *
 * The source document calls the streak the strongest driver of daily return, and
 * it is also the easiest thing in this phase to get subtly wrong: every case here
 * turns on a boundary rather than on arithmetic.
 */
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();

    GamificationAction::query()->update(['daily_cap' => null]);
});

/** Award once "on" a given Doha day, by travelling there. */
function awardOn(string $day, User $student, int $workspaceId, int $sourceId): void
{
    // Noon Doha, so the day is unambiguous whichever way the boundary is read.
    CarbonImmutable::setTestNow(CarbonImmutable::parse($day.' 12:00', 'Asia/Qatar'));

    app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $student->getKey(),
        actionKey: 'session_attended',
        sourceType: 'streak',
        sourceId: $sourceId,
        workspaceId: $workspaceId,
    ));

    CarbonImmutable::setTestNow();
}

function streakOf(User $student): StudentProgress
{
    return StudentProgress::query()->where('user_id', $student->getKey())->sole();
}

it('grows by one on each consecutive day', function (): void {
    $ws = (int) $this->workspace->getKey();

    awardOn('2026-08-10', $this->student, $ws, 1);
    expect(streakOf($this->student)->current_streak)->toBe(1);

    awardOn('2026-08-11', $this->student, $ws, 2);
    expect(streakOf($this->student)->current_streak)->toBe(2);

    awardOn('2026-08-12', $this->student, $ws, 3);
    expect(streakOf($this->student)->current_streak)->toBe(3);
});

/*
 * ⚠️ TWO AWARDS IN ONE DAY ARE ONE DAY.
 *
 * The statement is `WHERE last_active_day <> :today`, so the second award of the
 * day affects zero rows. Written as a read-then-write, a busy Tuesday would look
 * like a three-day run.
 */
it('does not advance twice in one day', function (): void {
    $ws = (int) $this->workspace->getKey();

    awardOn('2026-08-10', $this->student, $ws, 1);
    awardOn('2026-08-10', $this->student, $ws, 2);
    awardOn('2026-08-10', $this->student, $ws, 3);

    expect(streakOf($this->student)->current_streak)->toBe(1);
});

it('resets after a missed day and keeps the best ever reached', function (): void {
    $ws = (int) $this->workspace->getKey();

    awardOn('2026-08-10', $this->student, $ws, 1);
    awardOn('2026-08-11', $this->student, $ws, 2);
    awardOn('2026-08-12', $this->student, $ws, 3);

    // Two days missed: no shield covers that.
    awardOn('2026-08-15', $this->student, $ws, 4);

    $progress = streakOf($this->student);

    expect($progress->current_streak)->toBe(1)
        ->and($progress->best_streak)->toBe(3);
});

/*
 * ⚠️ A FROZEN DAY IS NEITHER ACTIVITY NOR ABSENCE (FR-015).
 *
 * A holiday the teacher declared must not cost a student their run — and the
 * question is asked through a contract, because the freeze period is
 * workspace-scoped while the streak belongs to the person.
 */
it('is not broken by a freeze period', function (): void {
    $ws = (int) $this->workspace->getKey();

    awardOn('2026-08-10', $this->student, $ws, 1);

    FreezePeriod::query()->create([
        'workspace_id' => $ws,
        'student_user_id' => null,
        'starts_on' => '2026-08-11',
        'ends_on' => '2026-08-13',
        'reason' => 'عطلة',
        'created_by' => $this->owner->getKey(),
    ]);

    awardOn('2026-08-14', $this->student, $ws, 2);

    // Three days passed with no activity and the run continued: every one of them
    // was frozen.
    expect(streakOf($this->student)->current_streak)->toBe(2);
});

it('breaks when only part of the gap was frozen', function (): void {
    $ws = (int) $this->workspace->getKey();

    awardOn('2026-08-10', $this->student, $ws, 1);

    FreezePeriod::query()->create([
        'workspace_id' => $ws,
        'student_user_id' => null,
        'starts_on' => '2026-08-11',
        'ends_on' => '2026-08-11',
        'reason' => 'عطلة',
        'created_by' => $this->owner->getKey(),
    ]);

    // The 12th and 13th were ordinary days the student simply missed — two of
    // them, so no shield covers it either.
    awardOn('2026-08-14', $this->student, $ws, 2);

    expect(streakOf($this->student)->current_streak)->toBe(1);
});

it('spends a shield to save a single missed day, once', function (): void {
    $ws = (int) $this->workspace->getKey();

    awardOn('2026-08-10', $this->student, $ws, 1);
    awardOn('2026-08-11', $this->student, $ws, 2);

    StudentProgress::query()->where('user_id', $this->student->getKey())->update(['shield_count' => 1]);

    // The 12th is missed; the 13th brings them back.
    awardOn('2026-08-13', $this->student, $ws, 3);

    $progress = streakOf($this->student);

    expect($progress->current_streak)->toBe(3)
        ->and($progress->shield_count)->toBe(0);
});

/*
 * ⚠️ ONE SHIELD PER MISSED DAY, EVEN IF THE EVALUATION RUNS TWICE.
 *
 * `streak_evaluated_day` is stamped inside the same statement that spends the
 * shield — the shape `notified_dormant_at` was added for in spec 006, after 72
 * accumulated passes drained a budget in one second.
 */
it('never spends two shields for one missed day', function (): void {
    $ws = (int) $this->workspace->getKey();

    awardOn('2026-08-10', $this->student, $ws, 1);

    StudentProgress::query()->where('user_id', $this->student->getKey())->update(['shield_count' => 2]);

    // Two awards on the returning day: the first spends the shield, the second
    // finds the day already evaluated.
    awardOn('2026-08-12', $this->student, $ws, 2);
    awardOn('2026-08-12', $this->student, $ws, 3);

    expect(streakOf($this->student)->shield_count)->toBe(1);
});

it('starts at one for a student who has never been active', function (): void {
    awardOn('2026-08-10', $this->student, (int) $this->workspace->getKey(), 1);

    $progress = streakOf($this->student);

    expect($progress->current_streak)->toBe(1)
        ->and($progress->best_streak)->toBe(1)
        ->and($progress->last_active_day)->toBe('2026-08-10');
});
