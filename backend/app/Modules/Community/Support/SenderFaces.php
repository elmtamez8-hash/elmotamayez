<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\Message;
use App\Modules\Identity\Support\AccountPhoto;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Whether a message carries its sender's face, and the eager load that makes it
 * free when it does.
 *
 * ⚠️ PRIVATE THREADS ONLY. A private thread has two people who already know each
 * other and the screen draws the face beside every incoming run. A room (session,
 * lesson, cohort) draws none — thirty faces down one column is noise — and an API
 * that sent them anyway would hand every classmate every other child's photograph
 * for a picture nothing shows. What the screen does not show, the payload does
 * not carry.
 *
 * ⚠️ AND THE DECISION IS A STAMP, NOT A RELATION TEST IN THE RESOURCE. A Resource
 * runs once per row; reading the photo there without this load is one query per
 * message under whatever workspace scope the reader happens to be in. The flag is
 * set here, beside the load, and the Resource reads the flag.
 */
final class SenderFaces
{
    /**
     * @param  Collection<int, Message>  $messages  all from `$conversation`
     */
    public static function stamp(Collection $messages, Conversation $conversation): void
    {
        if ($conversation->kind->isPublic() || $messages->isEmpty()) {
            return;
        }

        // Onto the same instances, whatever collection class the caller holds.
        (new EloquentCollection($messages->all()))->loadMissing(['sender', ...AccountPhoto::eagerLoads('sender')]);

        foreach ($messages as $message) {
            $message->showsSenderFace = true;
        }
    }

    /** One message, whose conversation is looked up (unscoped) for its kind. */
    public static function stampOne(Message $message): Message
    {
        $conversation = Conversation::query()
            ->withoutWorkspaceScope()
            ->whereKey($message->conversation_id)
            ->first();

        if ($conversation instanceof Conversation) {
            self::stamp(new Collection([$message]), $conversation);
        }

        return $message;
    }
}
