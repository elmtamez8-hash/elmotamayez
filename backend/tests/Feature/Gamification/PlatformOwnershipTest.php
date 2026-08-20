<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Models\CoinBalance;
use App\Modules\Gamification\Models\StudentProgress;

/**
 * One person, one progress file — and one purse per teacher (Q0 · FR-028أ ·
 * FR-028ج · SC-017).
 *
 * ⚠️ THE MIRROR-IMAGE BUG IS THE SILENT ONE. Nothing in the suite fails if
 * `student_progress` gains BelongsToWorkspace: it would simply start producing a
 * separate level, streak and badge set for every teacher the student studies
 * with, and the student would watch their own level drop by switching context.
 * The constitution requires a test in both directions for exactly this reason.
 */
it('gives a student studying with three teachers ONE progress file and three purses', function (): void {
    $student = User::factory()->create();

    $workspaces = collect(range(1, 3))->map(function (int $n) {
        [$workspace] = $this->createWorkspaceWithOwner(['name' => "Academy {$n}"]);

        return $workspace;
    });

    foreach ($workspaces as $index => $workspace) {
        app(AwardPoints::class)->handle(new AwardRequest(
            studentUserId: (int) $student->getKey(),
            actionKey: 'session_attended',
            sourceType: 'class_session',
            sourceId: $index + 1,
            workspaceId: (int) $workspace->getKey(),
        ));
    }

    expect(StudentProgress::query()->where('user_id', $student->getKey())->count())->toBe(1)
        ->and(CoinBalance::query()->withoutWorkspaceScope()->where('user_id', $student->getKey())->count())->toBe(3);

    // The experience accumulated across all three; the coins did not.
    $progress = StudentProgress::query()->where('user_id', $student->getKey())->sole();

    $purses = CoinBalance::query()->withoutWorkspaceScope()
        ->where('user_id', $student->getKey())
        ->pluck('coins');

    expect($progress->xp)->toBe(30)
        ->and($purses->all())->toBe([5, 5, 5]);
});

/*
 * ⚠️ AND THERE IS NO CORRECT TOTAL, WHICH IS WHY NO PAYLOAD OFFERS ONE.
 *
 * 15 coins is a number the student can never spend: the shop refuses it on the
 * first attempt, because a purse belongs to one teacher. A displayed total
 * promises something the product does not have.
 */
it('keeps coins unspendable outside the teacher they were earned with', function (): void {
    $student = User::factory()->create();

    [$first] = $this->createWorkspaceWithOwner(['name' => 'First']);
    [$second] = $this->createWorkspaceWithOwner(['name' => 'Second']);

    app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $student->getKey(),
        actionKey: 'session_attended',
        sourceType: 'class_session',
        sourceId: 1,
        workspaceId: (int) $first->getKey(),
    ));

    $inSecond = CoinBalance::query()->withoutWorkspaceScope()
        ->where('user_id', $student->getKey())
        ->where('workspace_id', $second->getKey())
        ->first();

    expect($inSecond)->toBeNull();
});
