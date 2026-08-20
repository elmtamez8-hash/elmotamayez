<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Models\User;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\BadgeAward;
use App\Modules\Gamification\Models\CoinBalance;
use App\Modules\Gamification\Models\Level;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\Gamification\Support\ProgressWriter;
use App\Shared\Actions\Action;

/**
 * Everything the profile screen shows, in a bounded number of queries.
 *
 * ⚠️ THE COUNT DOES NOT GROW WITH THE NUMBER OF BADGES OR TEACHERS. Five queries
 * whether the student holds one badge or forty: the progress row, the awards, the
 * badge catalogue (once, keyed by slug), the purses with their owners eager
 * loaded, and the level ladder. `QueryBudgetTest` measures it against DOUBLE the
 * fixture — a budget checked against one size passes over an N+1 by definition.
 */
class BuildProgressPayload extends Action
{
    public function __construct(private readonly ProgressWriter $progress) {}

    /** @return array{0: StudentProgress, 1: array<string, mixed>} */
    public function handle(User $student): array
    {
        $progress = $this->progress->progressFor((int) $student->getKey());

        /*
        | ⚠️ THE CATALOGUE IS FETCHED ONCE AND KEYED BY SLUG, not joined.
        | `badge_awards.badge_key` is TEXT rather than a foreign key — a retired
        | badge has to stay readable on the profile it was earned on — so `with()`
        | has nothing to load and a per-row lookup would be the N+1.
        */
        $catalogue = Badge::query()
            ->get()
            ->mapWithKeys(fn (Badge $badge): array => [
                $badge->key => ['name_ar' => $badge->name_ar, 'icon' => $badge->icon],
            ])
            ->all();

        $badges = BadgeAward::query()
            ->where('user_id', $student->getKey())
            ->orderByDesc('awarded_at')
            ->get()
            ->map(fn (BadgeAward $award): array => [
                'key' => $award->badge_key,
                // Falls back to the key itself: a badge retired from the
                // catalogue stays visible on the profile it was earned on.
                'name_ar' => $catalogue[$award->badge_key]['name_ar'] ?? $award->badge_key,
                'icon' => $catalogue[$award->badge_key]['icon'] ?? null,
                'awarded_at' => $award->awarded_at->toIso8601String(),
            ])
            ->all();

        /*
        | ⚠️ withoutWorkspaceScope() FILTERED EXPLICITLY BY user_id.
        |
        | The reader is a student, and a student belongs to no workspace at all —
        | so WorkspaceContext::id() is null, WorkspaceScope adds no condition, and
        | BelongsToWorkspace guards NOTHING here. The user_id filter is the guard,
        | and it is stricter than a workspace filter rather than looser. Precedent,
        | in the same words: Payments\Models\CreditBalance.
        */
        $purses = CoinBalance::query()
            ->withoutWorkspaceScope()
            ->where('user_id', $student->getKey())
            // One query for every teacher's name, instead of one per purse.
            ->with('workspace.owner:id,first_name,last_name')
            ->get()
            ->map(function (CoinBalance $balance): array {
                $workspace = $balance->workspace;
                $owner = $workspace?->owner;

                return [
                    'workspace_uuid' => $workspace?->uuid,
                    // A purse whose teacher account is gone still shows its
                    // coins: the student earned them, and hiding the row would
                    // make the balance unexplainable rather than private.
                    'teacher_name' => $owner === null ? '' : trim($owner->first_name.' '.$owner->last_name),
                    'coins' => $balance->coins,
                ];
            })
            ->all();

        $levels = Level::query()->orderBy('xp_threshold')->get();

        $current = $levels->last(fn (Level $level): bool => $level->xp_threshold <= $progress->xp);
        $next = $levels->first(fn (Level $level): bool => $level->xp_threshold > $progress->xp);

        return [$progress, [
            'level_name_ar' => $current?->name_ar,
            'next_level_xp' => $next?->xp_threshold,
            'badges' => $badges,
            'coin_balances' => $purses,
        ]];
    }
}
