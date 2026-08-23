<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Community\Models\Message;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Auth\Access\Response;

/**
 * What may be done to one message.
 *
 * Only the sender's own hide lives here in this phase; the moderator's hide, the
 * ban and the «useful» mark arrive with US3 and carry their own permissions.
 */
class MessagePolicy
{
    /**
     * Hiding your own message (FR-015).
     *
     * ⚠️ THE SENDER, AND NOT «A PARTY». Every participant can read the message;
     * only the person who wrote it may take it back. A policy that asked
     * membership here would let either side erase the other's words, which is the
     * feature nobody asked for wearing the name of the one they did.
     */
    public function hide(User $user, Message $message): Response
    {
        return (int) $message->sender_user_id === (int) $user->getKey()
            ? Response::allow()
            : Response::deny('يمكنك حذف رسائلك أنت فقط.');
    }

    /**
     * Endorsing an answer as useful (`FR-023`).
     *
     * ⚠️ `chat.reply`, WHICH IS THE TEACHER'S SIDE OF A CHAT AND NOT MODERATION.
     * Endorsing is participation — the assistant who answers questions is exactly
     * the person who should be able to pin a good answer — while hiding and
     * banning are `chat.moderate`, ticked separately.
     *
     * ⚠️ AND MEMBERSHIP IS ASKED AS WELL AS THE PERMISSION. A student is a member
     * of no workspace in production, so the permission alone would look
     * sufficient; in a fixture it is not, and the pair is what actually separates
     * the sides. The author especially is refused: an endorsement anybody can give
     * themselves is a points button on every message a student writes.
     */
    public function markHelpful(User $user, Message $message): Response
    {
        $isMember = $user->workspaces()
            ->withoutGlobalScopes()
            ->whereKey($message->workspace_id)
            ->exists();

        if (! $isMember || ! $user->hasPermissionTo(Permissions::CHAT_REPLY)) {
            return Response::deny('اعتماد الإجابات من صلاحيّة المدرّس ومن فوّضه.');
        }

        return Response::allow();
    }
}
