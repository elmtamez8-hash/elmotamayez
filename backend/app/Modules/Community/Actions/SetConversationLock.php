<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Close the room's discussion, or open it again (`FR-018`).
 *
 * ⚠️ ROOMS ONLY, AND THE REFUSAL IS EXPLICIT. A private thread between one
 * teacher and one student has no «الجميع» to quiet: locking it would be a
 * teacher silencing one person, which is what a BAN is — declared workspace-wide,
 * recorded, and appealable. Two ways to silence somebody, one of them unrecorded,
 * is the second way nobody audits.
 *
 * The lock is idempotent on purpose: pressing «أغلق» twice must not move the
 * timestamp, because the timestamp is the record of WHEN the teacher closed it
 * and a second press is not a second decision.
 */
class SetConversationLock extends Action
{
    public function handle(Conversation $conversation, User $actor, bool $locked): Conversation
    {
        if (! $conversation->kind->isPublic()) {
            throw new DomainException('لا يمكن إغلاق محادثةٍ خاصّة. لإيقاف شخصٍ بعينه استخدم الإيقاف.');
        }

        if ($locked === ($conversation->locked_at !== null)) {
            return $conversation;
        }

        // Not fillable, for the reason `last_message_id` is not: the column
        // carries a moderation decision and must be written by this Action alone.
        $conversation->forceFill(['locked_at' => $locked ? now() : null])->save();

        return $conversation;
    }
}
