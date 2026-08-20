<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Actions\EvaluateBadges;
use App\Modules\Gamification\Actions\ReverseAward;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\BadgeAward;
use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;

/**
 * Levels ratchet, badges award once, and neither is withdrawn (FR-012 · FR-017 ·
 * SC-007).
 */
beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();

    GamificationAction::query()->update(['daily_cap' => null]);
});

function award(User $student, int $workspaceId, int $sourceId, string $key = 'session_attended'): void
{
    app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $student->getKey(),
        actionKey: $key,
        sourceType: 'badge-test',
        sourceId: $sourceId,
        workspaceId: $workspaceId,
    ));
}

it('raises the level once the threshold is crossed, and congratulates once', function (): void {
    $ws = (int) $this->workspace->getKey();

    // The seeded ladder puts level 2 at 100 xp; the action is worth 10.
    foreach (range(1, 10) as $n) {
        award($this->student, $ws, $n);
    }

    $progress = StudentProgress::query()->where('user_id', $this->student->getKey())->sole();

    expect($progress->xp)->toBe(100)
        ->and($progress->level)->toBe(2);

    // More awards at the same level: no second congratulation.
    award($this->student, $ws, 11);

    expect(Notification::query()->where('type', NotificationType::LevelUp->value)->count())->toBe(1);
});

/*
 * ⚠️ LEVELS ARE A RATCHET, AND A PENALTY IS WHAT PROVES IT.
 *
 * Experience can go down — a penalty is an ordinary catalogue row — so a level
 * recomputed and written unconditionally would demote the student and then
 * "promote" them again on their next award, firing the event and a congratulation
 * every single time.
 */
it('never lowers a level when experience is deducted', function (): void {
    $ws = (int) $this->workspace->getKey();

    foreach (range(1, 10) as $n) {
        award($this->student, $ws, $n);
    }

    expect(StudentProgress::query()->where('user_id', $this->student->getKey())->sole()->level)->toBe(2);

    award($this->student, $ws, 99, 'payment_overdue');

    $progress = StudentProgress::query()->where('user_id', $this->student->getKey())->sole();

    expect($progress->xp)->toBe(70)
        ->and($progress->level)->toBe(2);

    // And climbing back over the threshold does not congratulate a second time.
    foreach (range(20, 23) as $n) {
        award($this->student, $ws, $n);
    }

    expect(Notification::query()->where('type', NotificationType::LevelUp->value)->count())->toBe(1);
});

it('awards a badge once and never again', function (): void {
    $ws = (int) $this->workspace->getKey();

    // `first_steps` is 50 xp in the seeded catalogue.
    foreach (range(1, 5) as $n) {
        award($this->student, $ws, $n);
    }

    // The evaluator runs from a queued job that a worker can replay.
    app(EvaluateBadges::class)->handle($this->student);
    app(EvaluateBadges::class)->handle($this->student);

    expect(BadgeAward::query()->where('user_id', $this->student->getKey())->where('badge_key', 'first_steps')->count())
        ->toBe(1)
        ->and(Notification::query()->where('type', NotificationType::BadgeAwarded->value)->count())->toBe(1);
});

it('keeps a badge after its rule is changed', function (): void {
    $ws = (int) $this->workspace->getKey();

    foreach (range(1, 5) as $n) {
        award($this->student, $ws, $n);
    }

    expect(BadgeAward::query()->where('badge_key', 'first_steps')->exists())->toBeTrue();

    // The operator raises the bar far above what this student has.
    Badge::query()->where('key', 'first_steps')->update(['rule_value' => 100_000]);

    app(EvaluateBadges::class)->handle($this->student);

    // What they earned under the old rule, they earned.
    expect(BadgeAward::query()->where('badge_key', 'first_steps')->exists())->toBeTrue();
});

it('ignores a disabled badge', function (): void {
    Badge::query()->update(['is_active' => false]);

    $ws = (int) $this->workspace->getKey();

    foreach (range(1, 5) as $n) {
        award($this->student, $ws, $n);
    }

    expect(BadgeAward::query()->count())->toBe(0);
});

/*
 * ⚠️ A COUNTING RULE NETS OFF REVERSALS.
 *
 * Counting rows alone would let a student keep "twenty sessions attended" earned
 * partly from sessions a teacher later marked absent — and would count each of
 * those sessions TWICE, once for the award and once for its correction.
 */
it('does not count a reversed award towards a counting badge', function (): void {
    $ws = (int) $this->workspace->getKey();

    Badge::query()->where('key', 'regular_attender')->update(['rule_value' => 2]);

    $first = app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $this->student->getKey(),
        actionKey: 'session_attended',
        sourceType: 'class_session',
        sourceId: 1,
        workspaceId: $ws,
    ));

    award($this->student, $ws, 2);

    app(ReverseAward::class)->handle($first);

    BadgeAward::query()->delete();
    app(EvaluateBadges::class)->handle($this->student);

    // One award plus one reversal plus one award = one countable session.
    expect(BadgeAward::query()->where('badge_key', 'regular_attender')->exists())->toBeFalse();
});
