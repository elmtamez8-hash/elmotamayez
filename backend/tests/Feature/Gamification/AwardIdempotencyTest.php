<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Gamification\Models\StudentProgress;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * The same event, delivered again, pays once (FR-008 · SC-003).
 *
 * Every listener in this module is queued, and a queue delivers at least once —
 * a worker killed after the award and before the ack replays it.
 */
beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();

    // Out of the way, so this file measures the idempotency key and not the cap.
    GamificationAction::query()->update(['daily_cap' => null]);
});

it('writes one entry however many times the same event arrives', function (): void {
    $award = fn () => app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $this->student->getKey(),
        actionKey: 'session_attended',
        sourceType: 'class_session',
        sourceId: 77,
        workspaceId: (int) $this->workspace->getKey(),
    ));

    $first = $award();

    foreach (range(1, 9) as $ignored) {
        expect($award())->toBeNull();
    }

    expect($first)->not->toBeNull()
        ->and(AwardEntry::query()->count())->toBe(1);

    /*
    | ⚠️ AND THE AGGREGATE MOVED ONCE, which is the half a row count cannot see.
    | If the aggregate were moved BEFORE the entry, every redelivery would add
    | experience while the duplicate entry was ignored — one row, ten times the
    | points, and the balance permanently out of step with the sum of its entries
    | with nothing anywhere reporting a problem.
    */
    $action = GamificationAction::query()->where('key', 'session_attended')->sole();

    expect(StudentProgress::query()->where('user_id', $this->student->getKey())->sole()->xp)
        ->toBe($action->xp);
});

/*
 * ⚠️ THE OTHER DIRECTION, AND IT IS THE ONE THAT MAKES THE TEST ABOVE WORTH
 * HAVING.
 *
 * `insertOrIgnore` reports "zero rows" for a genuine duplicate AND for a real
 * failure it swallowed — a null, a foreign key, an out-of-range value. An
 * implementation that treated both as "already recorded" would write nothing at
 * all and pass every assertion above by finding the zero it expected. So the
 * Action reads the row back by its key and THROWS when there is no such row.
 */
it('throws rather than reporting success when nothing was written', function (): void {
    /*
    | Reproducing the condition takes a fixed uuid, because the failures that
    | cause it in production cannot be raised locally: SQLite enforces neither
    | foreign keys nor string lengths, so a bad student id or an over-long key —
    | the realistic causes — insert happily here and only fail on MySQL.
    |
    | What matters is the SHAPE: the insert writes zero rows AND there is no
    | matching entry. Pinning the uuid and pre-taking it produces exactly that,
    | on both engines.
    */
    $collision = '11111111-2222-3333-4444-555555555555';
    Str::createUuidsUsing(fn () => Uuid::fromString($collision));

    AwardEntry::query()->insert([
        'uuid' => $collision,
        'student_user_id' => $this->student->getKey(),
        'action_key' => 'something_else',
        'xp' => 0,
        'coins' => 0,
        'level_band' => 0,
        'source_type' => 'other',
        'source_id' => 1,
        'reversal_of_id' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        expect(fn () => app(AwardPoints::class)->handle(new AwardRequest(
            studentUserId: (int) $this->student->getKey(),
            actionKey: 'focus_session',
            sourceType: 'test',
            sourceId: 1,
        )))->toThrow(RuntimeException::class);
    } finally {
        Str::createUuidsNormally();
    }

    // And nothing was silently half-written: the transaction rolled back.
    expect(AwardEntry::query()->where('action_key', 'focus_session')->count())->toBe(0);
});

it('separates two different causes of the same action', function (): void {
    foreach ([11, 12] as $sourceId) {
        app(AwardPoints::class)->handle(new AwardRequest(
            studentUserId: (int) $this->student->getKey(),
            actionKey: 'session_attended',
            sourceType: 'class_session',
            sourceId: $sourceId,
            workspaceId: (int) $this->workspace->getKey(),
        ));
    }

    expect(AwardEntry::query()->count())->toBe(2);
});
