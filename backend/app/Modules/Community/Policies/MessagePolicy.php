<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Community\Models\Message;
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
}
