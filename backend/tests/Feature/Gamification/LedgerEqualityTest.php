<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Gamification\Models\StudentProgress;

/**
 * The balance equals the sum of its entries, always (FR-005 · SC-001).
 *
 * The one invariant everything else in this module rests on: the leaderboard is
 * rebuildable because the ledger is the truth, and the ledger is only the truth
 * while this holds.
 */
beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();

    GamificationAction::query()->update(['daily_cap' => null]);
});

it('keeps the aggregate equal to the sum of the entries over ten thousand awards', function (): void {
    $studentId = (int) $this->student->getKey();
    $workspaceId = (int) $this->workspace->getKey();

    foreach (range(1, 10_000) as $n) {
        app(AwardPoints::class)->handle(new AwardRequest(
            studentUserId: $studentId,
            actionKey: 'session_attended',
            sourceType: 'bulk',
            sourceId: $n,
            workspaceId: $workspaceId,
        ));
    }

    $progress = StudentProgress::query()->where('user_id', $studentId)->sole();

    expect(AwardEntry::query()->count())->toBe(10_000)
        ->and($progress->xp)->toBe((int) AwardEntry::query()->sum('xp'));
    /*
     * ⚠️ `slow` — والعددُ لا يُنقَص. هذه أبطأُ حالةٍ في الطقمِ كلِّه (٨٧ ثانيةً
     * مقيسة)، وقد اقتُرِحَ خفضُها إلى خمسِمئةٍ ثمَّ سُحِبَ الاقتراح: سليلُها في
     * `LedgerInvariantTest` يقولُ في تعليقِه «The number is the spec's»، فعشرةُ
     * الآلافِ رقمُ `SC-001` لا رقمٌ اختِيرَ هنا. توكيدٌ يُضعَفُ ليمرَّ أسرعَ هو
     * بوّابةٌ خضراءُ عن قياسٍ أصغر.
     *
     * والعلامةُ وصفٌ لا بوّابة: لا `--exclude-group` في `phpunit.xml` ولا في
     * `ci.yml`، ولا يُقصَدُ أن يكون. ما يُخرِجُ هذه الحالةَ من المسارِ الحرِجِ هو
     * تقسيمُ CI إلى شرائح، فتسقُطُ في شريحةٍ واحدةٍ من أربعٍ تعملُ بالتوازي مع
     * أخواتِها — بلا استثناءِ شيءٍ من البوّابة.
     */
})->group('slow');

/*
 * ⚠️ THE PENALTY LARGER THAN THE BALANCE — where FR-005 and FR-009 would
 * contradict each other if the entry recorded the nominal amount.
 *
 * A student with 20 experience and a −30 penalty. Write −30 in the entry and
 * floor the aggregate at zero and the sum of the entries is −10 while the
 * aggregate is 0, PERMANENTLY: the reconciliation job would then report a drift
 * every night that the design itself requires, and after the third night nobody
 * reads its output any more.
 */
it('records what was actually deducted, not the nominal penalty', function (): void {
    $studentId = (int) $this->student->getKey();
    $workspaceId = (int) $this->workspace->getKey();

    // 20 experience: two awards of the 10-point action.
    foreach ([1, 2] as $n) {
        app(AwardPoints::class)->handle(new AwardRequest(
            studentUserId: $studentId,
            actionKey: 'session_attended',
            sourceType: 'setup',
            sourceId: $n,
            workspaceId: $workspaceId,
        ));
    }

    expect(StudentProgress::query()->where('user_id', $studentId)->sole()->xp)->toBe(20);

    $penalty = app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: $studentId,
        actionKey: 'payment_overdue',
        sourceType: 'billing',
        sourceId: 1,
        workspaceId: $workspaceId,
    ));

    $progress = StudentProgress::query()->where('user_id', $studentId)->sole();

    expect(GamificationAction::query()->where('key', 'payment_overdue')->sole()->xp)->toBe(-30)
        // …but only 20 could be taken, so that is what the entry says.
        ->and($penalty->xp)->toBe(-20)
        ->and($progress->xp)->toBe(0)
        ->and($progress->xp)->toBe((int) AwardEntry::query()->sum('xp'));
});

it('never lets a balance go below zero', function (): void {
    $studentId = (int) $this->student->getKey();
    $workspaceId = (int) $this->workspace->getKey();

    // A penalty with nothing to take: the entry is zero, not negative.
    $penalty = app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: $studentId,
        actionKey: 'payment_overdue',
        sourceType: 'billing',
        sourceId: 1,
        workspaceId: $workspaceId,
    ));

    expect($penalty->xp)->toBe(0)
        ->and(StudentProgress::query()->where('user_id', $studentId)->sole()->xp)->toBe(0);
});
