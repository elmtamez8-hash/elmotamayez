<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Community\Models\ConversationWriteBan;

/**
 * «Is this person barred from writing in THIS thread right now?» (FR-047).
 *
 * `BanReader` literally, one column narrower, and for its reasons:
 *
 * ⚠️ THE MOST RECENT ROW WINS, because lifting is a stamp on a row and a second
 * ban is a new one. Asking «does an unlifted row exist» frees somebody the moment
 * an older ban is lifted, while they are serving a newer one.
 *
 * ⚠️ AND THE EXPIRY IS JUDGED IN PHP. `expires_at > now()` alone reads a
 * PERMANENT ban — `expires_at IS NULL` by definition — as expired the instant it
 * is made, and that is the one ban which must never end by itself. The pair has
 * to be taken together, and one half of it is not a comparison at all. In PHP
 * also because `DATE_ADD` on MySQL against `datetime()` on SQLite is two dialects
 * for one predicate — the reason `BanReader` and the freeze period both sit here.
 */
final class WriteBanReader
{
    public function activeBan(int $userId, int $conversationId): ?ConversationWriteBan
    {
        $latest = ConversationWriteBan::query()
            // The reader is asked on a student's write, and a student is a member
            // of no workspace — the global scope adds no condition for them, so
            // the thread is the whole filter.
            ->withoutWorkspaceScope()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->first();

        if ($latest === null || $latest->lifted_at !== null) {
            return null;
        }

        return $latest->expires_at === null || $latest->expires_at->isFuture() ? $latest : null;
    }

    /**
     * The same question for a whole screen, in ONE query.
     *
     * ⚠️ THE MODERATION LIST NEEDS THIS AND THE POLICY MUST NOT USE IT. Asking
     * `activeBan()` per row is an N+1 by construction on a roster; asking it once
     * for the page and then reusing the answer on the WRITE would be a decision
     * as old as the page it was rendered on. Same split `BanReader` already draws.
     *
     * ⚠️ AND «MOST RECENT WINS» IS APPLIED IN PHP, not by a `GROUP BY`. `MAX(id)`
     * grouped gives the id without the row beside it — a second query, or a window
     * function SQLite will not run. Ordered descending and taken first-seen: one
     * pass, one query, both engines.
     *
     * @param  list<int>  $userIds
     * @return array<int, ConversationWriteBan> keyed by user id; absent means free to write
     */
    public function activeAmong(array $userIds, int $conversationId): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = ConversationWriteBan::query()
            ->withoutWorkspaceScope()
            ->where('conversation_id', $conversationId)
            ->whereIn('user_id', $userIds)
            ->orderByDesc('id')
            ->get();

        $seen = [];
        $active = [];

        foreach ($rows as $row) {
            $user = (int) $row->user_id;

            if (array_key_exists($user, $seen)) {
                continue;
            }

            $seen[$user] = true;

            if ($row->lifted_at === null && ($row->expires_at === null || $row->expires_at->isFuture())) {
                $active[$user] = $row;
            }
        }

        return $active;
    }
}
