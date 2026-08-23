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
 * ⚠️ AND THE EXPIRY IS A GROUPED CONDITION. `NULL > now()` is NULL, so a
 * permanent ban — `expires_at IS NULL` by definition — reads as expired the
 * instant it is made unless the pair is taken together. It is evaluated in PHP
 * here for exactly that reason: two comparisons, one of which is not a
 * comparison. The same family as 013's legal-hold `whereNotIn` on a nullable
 * column, where the `orWhereNull` that fixed it had to be grouped or it discarded
 * the age bound with it.
 *
 * ⚠️ AND IT IS ASKED FROM ONE PLACE — `ConversationPolicy::post()` — WHICH IS WHY
 * THERE IS NO MEMO. The ban is workspace-wide by declaration, and putting the
 * check in the policy rather than in `PostMessage` is what makes
 * `StartConversation` inherit it: a banned person opening a fresh thread with the
 * teacher is the conversation the ban was about, and a guard on sending alone
 * would leave that door open.
 *
 * A per-request cache was written here first and taken out: reads never ask this
 * question, so the memo saved nothing on a page of fifty and bought a staleness
 * problem — a moderator lifting a ban inside the same process as the check would
 * be answered from a copy. One query per WRITE is the right cost.
 */
final class BanReader
{
    public function isBanned(int $userId, int $workspaceId): bool
    {
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
            return false;
        }

        return $latest->expires_at === null || $latest->expires_at->isFuture();
    }
}
