<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Gamification\Models\StudentProgress;

/**
 * The daily cap (FR-006 · SC-002).
 *
 * The second design control the source document says cannot be skipped: without
 * it a student discovers that answering 300 easy questions in an hour takes the
 * lead, and the ranking stops meaning anything.
 */
function awardOnce(User $student, string $actionKey, int $sourceId, ?int $workspaceId = null): ?AwardEntry
{
    return app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $student->getKey(),
        actionKey: $actionKey,
        sourceType: 'test',
        sourceId: $sourceId,
        workspaceId: $workspaceId,
    ));
}

beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();
});

it('stops awarding at the cap and lets the action itself succeed', function (): void {
    $action = GamificationAction::query()->where('key', 'session_attended')->sole();

    // ⚠️ TWICE the cap, not the cap plus one. A count-then-write implementation
    // can land one over; only a run well past the limit shows the counter holding.
    $awarded = 0;

    foreach (range(1, $action->daily_cap * 2) as $n) {
        if (awardOnce($this->student, 'session_attended', $n, (int) $this->workspace->getKey()) !== null) {
            $awarded++;
        }
    }

    expect($awarded)->toBe($action->daily_cap)
        ->and(AwardEntry::query()->where('student_user_id', $this->student->getKey())->count())
        ->toBe($action->daily_cap);

    /*
    | ⚠️ AND THE CALL AFTER THE CAP RETURNED null RATHER THAN THROWING. FR-006 has
    | two halves and this is the one an implementation forgets: reaching the cap
    | must not fail the thing that triggered it. A student who has hit today's
    | limit still attends the session, and an exception here would roll back the
    | attendance register with it.
    */
    expect(awardOnce($this->student, 'session_attended', 999, (int) $this->workspace->getKey()))->toBeNull();
});

it('awards without limit when the action has no cap', function (): void {
    GamificationAction::query()->where('key', 'session_attended')->update(['daily_cap' => null]);

    foreach (range(1, 12) as $n) {
        awardOnce($this->student, 'session_attended', $n, (int) $this->workspace->getKey());
    }

    expect(AwardEntry::query()->count())->toBe(12);
});

it('awards nothing for a disabled action, and nothing for an unknown one', function (): void {
    GamificationAction::query()->where('key', 'session_attended')->update(['is_active' => false]);

    expect(awardOnce($this->student, 'session_attended', 1, (int) $this->workspace->getKey()))->toBeNull()
        // An action with no row is an unfilled catalogue, not an error (FR-007).
        ->and(awardOnce($this->student, 'no_such_action', 1, (int) $this->workspace->getKey()))->toBeNull()
        ->and(AwardEntry::query()->count())->toBe(0)
        ->and(StudentProgress::query()->count())->toBe(0);
});

/*
 * ⚠️ THE CAP IS PER ACTION AND PER DAY, NOT PER STUDENT.
 *
 * A counter keyed on the student alone would let one busy action eat another's
 * allowance — and the failure would look like "gamification stopped working"
 * rather than like a bug in the key.
 */
it('keeps each action on its own allowance', function (): void {
    foreach (range(1, 4) as $n) {
        awardOnce($this->student, 'session_attended', $n, (int) $this->workspace->getKey());
    }

    expect(awardOnce($this->student, 'homework_submitted', 1, (int) $this->workspace->getKey()))->not->toBeNull();
});

it('keeps each student on their own allowance', function (): void {
    $other = User::factory()->create();

    foreach (range(1, 4) as $n) {
        awardOnce($this->student, 'session_attended', $n, (int) $this->workspace->getKey());
    }

    expect(awardOnce($other, 'session_attended', 1, (int) $this->workspace->getKey()))->not->toBeNull();
});

/*
 * The coin destination guard.
 *
 * A purse belongs to one teacher, so an action worth coins that fires outside any
 * workspace has nowhere correct to put them. Refusing is what rules out the
 * alternative: routing by WorkspaceContext, which resolves to `last_workspace_id`
 * for a student and would quietly let them pick whose shop to enrich.
 */
it('refuses a coin-bearing action with no workspace', function (): void {
    expect(fn () => awardOnce($this->student, 'session_attended', 1, null))
        ->toThrow(RuntimeException::class);
});

it('allows a coinless action with no workspace', function (): void {
    expect(awardOnce($this->student, 'focus_session', 1, null))->not->toBeNull();
});
