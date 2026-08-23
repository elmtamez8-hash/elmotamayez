<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Community\Enums\ModerationVerdict;
use App\Modules\Community\Models\ModerationAction;

/**
 * «Is this person banned in this workspace right now?» (`FR-022`).
 *
 * ⚠️ THE MOST RECENT ROW WINS, because lifting a ban is a NEW ROW and not a
 * deletion. Asking «does a ban row exist» would keep somebody banned for ever
 * after they were forgiven, and asking «does an unban row exist» would free
 * somebody banned a second time.
 *
 * ⚠️ AND THE EXPIRY IS A GROUPED PREDICATE. `NULL > now()` is NULL, so a
 * permanent ban — `expires_at IS NULL` by definition — reads as expired the
 * instant it is made unless the pair is parenthesised. The same family as 013's
 * legal-hold `whereNotIn` on a nullable column, where the `orWhereNull` that fixed
 * it had to be grouped or it discarded the age bound with it.
 *
 * ⚠️ AND IT IS ASKED FROM TWO PLACES. The ban is workspace-wide by declaration, so
 * `PostMessage` and `StartConversation` both consult it — one guard on sending
 * alone leaves the banned person opening a fresh thread with the teacher, which
 * is the conversation the ban was about.
 *
 * Memoised per request: a page of fifty messages must not be fifty queries, and
 * the reader is `scoped()` so a queue worker does not carry one job's answer into
 * the next.
 */
final class BanReader
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function isBanned(int $userId, int $workspaceId): bool
    {
        $key = $userId.':'.$workspaceId;

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $latest = ModerationAction::query()
            // The reader is asked on a student's write, and a student is a member
            // of no workspace — the global scope adds no condition for them, so
            // the workspace is named explicitly.
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('subject_type', ModerationAction::SUBJECT_USER)
            ->where('subject_id', $userId)
            ->whereIn('verdict', [ModerationVerdict::Banned->value, ModerationVerdict::Unbanned->value])
            ->orderByDesc('id')
            ->first(['verdict', 'expires_at']);

        if ($latest === null || $latest->verdict !== ModerationVerdict::Banned) {
            return $this->memo[$key] = false;
        }

        // ⚠️ Grouped, and in PHP rather than in SQL for the same reason — a null
        // `expires_at` is permanent, and comparing it to a time yields neither
        // true nor false.
        return $this->memo[$key] = $latest->expires_at === null || $latest->expires_at->isFuture();
    }

    /** Forget one answer — the moderator just changed it. */
    public function forget(int $userId, int $workspaceId): void
    {
        unset($this->memo[$userId.':'.$workspaceId]);
    }
}
