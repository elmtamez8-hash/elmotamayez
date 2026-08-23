<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Community\Models\Message;
use App\Modules\Gamification\Actions\ReadRanksFor;
use App\Modules\Gamification\Support\LeaderboardScope;
use Illuminate\Support\Collection;

/**
 * The rank and the level that sit beside a name in a public room (`FR-019`).
 *
 * ⚠️ ONE CALL FOR THE WHOLE PAGE. A Resource runs once per row, so a lookup
 * inside one is an N+1 by construction — `ReadRanksFor` was built bulk for
 * exactly this caller, and `RankFallbackTest` measures the flatness at two sizes.
 *
 * ⚠️ AND ABSENCE IS A STATE, NOT AN ERROR. A teacher and an assistant are on no
 * board at all; a student who joined this morning has no row either, because the
 * boards roll up nightly. Anyone the read does not know about is stamped with
 * nulls, and the screen renders nothing where the badge would go — never a zero,
 * which reads as «المركز ٠» beside the teacher's own name.
 *
 * ⚠️ AND THE SCOPE IS THE TEACHER'S, keyed by the workspace, for both kinds of
 * room. A session chat and a lesson chat live in one workspace, so one key serves
 * both — `LeaderboardScope::keyFor()` is what says so, rather than a string built
 * here that would drift from the roll-up's own.
 */
final class ChatRankStamper
{
    public function __construct(private readonly ReadRanksFor $ranks) {}

    /**
     * @param  Collection<int, Message>  $messages
     */
    public function stamp(Collection $messages, int $workspaceId): void
    {
        if ($messages->isEmpty()) {
            return;
        }

        /** @var list<int> $userIds */
        $userIds = array_values(array_unique(
            $messages
                ->pluck('sender_user_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->all()
        ));

        $badges = $this->ranks->handle($userIds, LeaderboardScope::Teacher->keyFor((string) $workspaceId));

        foreach ($messages as $message) {
            $badge = $badges[(int) $message->sender_user_id] ?? null;

            // Set on the model rather than passed to the Resource, so the payload
            // shape is the same whichever entrance built the page.
            $message->senderRank = $badge['rank'] ?? null;
            $message->senderLevel = $badge['level'] ?? null;
        }
    }
}
