<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Models\Message;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * The sender takes their own message back (`FR-015`).
 *
 * ⚠️ THE ROW STAYS. Hiding removes the words from every reader's page and leaves
 * the record for the moderator, which is the whole distinction between this and a
 * delete — a chat where the offending line vanishes on request is a chat with no
 * moderation at all.
 */
class HideMessage extends Action
{
    public function handle(User $actor, string $messageUuid): Message
    {
        $message = Message::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $messageUuid)
            ->first();

        if (! $message instanceof Message) {
            throw new ModelNotFoundException('لم نجد هذه الرسالة.');
        }

        Gate::forUser($actor)->authorize('hide', $message);

        // Claimed rather than assigned: a second tap must not restamp the time,
        // which is the moment the words stopped being readable.
        Message::query()
            ->withoutWorkspaceScope()
            ->whereKey($message->getKey())
            ->whereNull('hidden_at')
            ->update(['hidden_at' => now()]);

        return $message->refresh();
    }
}
