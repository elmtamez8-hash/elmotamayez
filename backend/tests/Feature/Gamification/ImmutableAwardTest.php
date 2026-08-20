<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Gamification\Models\StudentProgress;

/**
 * The ledger is append-only (FR-004), and re-pricing is not retroactive (FR-003 ·
 * SC-005).
 */
beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();
});

function anAward(User $student, int $workspaceId, int $sourceId = 1): AwardEntry
{
    return app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $student->getKey(),
        actionKey: 'session_attended',
        sourceType: 'test',
        sourceId: $sourceId,
        workspaceId: $workspaceId,
    ));
}

/*
 * Refused on the MODEL, not only in the Action.
 *
 * The Action is the path the API uses; a seeder, a Filament resource and a future
 * console command all reach the model directly. A ledger with one honest route
 * and three quiet ones is not a ledger. Same shape as Settlement's LedgerEntry.
 */
it('refuses to update or delete an entry', function (): void {
    $entry = anAward($this->student, (int) $this->workspace->getKey());

    // Captured BEFORE the attempt: Eloquent fills the attribute on the in-memory
    // model and only then fires `updating`, so `$entry->xp` reads 999 afterwards
    // even though nothing reached the database. Asserting against it would be
    // asserting against the failed write's own value.
    $awarded = $entry->xp;

    expect(fn () => $entry->update(['xp' => 999]))->toThrow(RuntimeException::class)
        ->and(fn () => $entry->delete())->toThrow(RuntimeException::class);

    expect(AwardEntry::query()->sole()->xp)->toBe($awarded);
});

it('applies a re-priced action from then on, and never backwards', function (): void {
    $workspaceId = (int) $this->workspace->getKey();

    $first = anAward($this->student, $workspaceId, 1);
    $originalValue = $first->xp;

    // The operator doubles it from the panel — no deploy (FR-002).
    GamificationAction::query()->where('key', 'session_attended')->update(['xp' => $originalValue * 2]);

    $second = anAward($this->student, $workspaceId, 2);

    expect($second->xp)->toBe($originalValue * 2)
        // The entry already written keeps the value it was awarded at: what the
        // student was told they earned is what they earned.
        ->and($first->refresh()->xp)->toBe($originalValue)
        ->and(StudentProgress::query()->where('user_id', $this->student->getKey())->sole()->xp)
        ->toBe($originalValue * 3);
});
