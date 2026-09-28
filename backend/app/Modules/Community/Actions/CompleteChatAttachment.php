<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use App\Modules\Media\Actions\CompleteMediaUpload;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaLimits;
use App\Shared\Actions\Action;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * «The bytes are there» — for a picture or a voice note in a chat.
 *
 * ⚠️ THIS USED TO BE `POST /media/assets/{asset}/complete`, AND THAT DOOR ASKS A
 * TEACHER'S QUESTION. `MediaAssetPolicy::view()` is «may you manage lesson
 * content in this workspace» (`LESSONS_MANAGE` + membership), so a student —
 * who holds no permission and is a member of no workspace — was refused every
 * picture with «لا تملك صلاحية لهذا الإجراء» AFTER the bytes had landed, and an
 * assistant who answers the chat without editing lessons was refused the same
 * way. Only the owner's own uploads ever completed, which is why it looked like
 * it worked. Measured on production on 2026-09-28: the `PUT` answered 200 and
 * the `complete` answered 403.
 *
 * ⚠️ A SIBLING DOOR, NOT A BRANCH IN THE LESSON POLICY. Media knowing what a
 * `Conversation` is would be a dependency pointing the wrong way, and the lesson
 * path stays byte-for-byte what it was. What is shared is the part worth
 * sharing: `CompleteMediaUpload` reads the file and settles the row, for both.
 *
 * ⚠️ TWO QUESTIONS, AND NEITHER IMPLIES THE OTHER:
 *  - may this person WRITE in the thread (`ConversationPolicy::post`) — the same
 *    guard the ticket and the message ask, so a ban or an ended enrolment that
 *    lands mid-upload is refused here too;
 *  - is this THEIR upload (`uploaded_by_user_id`) — the thread has two ends, and
 *    the other end may post there without having sent this file.
 *
 * Both identifiers are resolved without the workspace scope and checked by hand,
 * because for a student the scope adds no condition at all.
 */
class CompleteChatAttachment extends Action
{
    public function __construct(private readonly CompleteMediaUpload $complete) {}

    public function handle(User $sender, string $conversationUuid, string $assetUuid): MediaAsset
    {
        $conversation = Conversation::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $conversationUuid)
            ->first();

        if (! $conversation instanceof Conversation) {
            throw new ModelNotFoundException('لم نجد هذه المحادثة.');
        }

        if (Gate::forUser($sender)->denies('post', $conversation)) {
            throw new AuthorizationException('لا يمكنك الإرسال في هذه المحادثة.');
        }

        $asset = MediaAsset::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $assetUuid)
            ->where('owner_type', Conversation::class)
            ->where('owner_id', $conversation->getKey())
            ->first();

        // Another thread's asset and a lesson video answer the same as a uuid that
        // does not exist: this route reveals nothing about files it does not own.
        if (! $asset instanceof MediaAsset) {
            throw new ModelNotFoundException('لم نجد المرفق. أعد رفعه.');
        }

        if ((int) $asset->uploaded_by_user_id !== (int) $sender->getKey()) {
            throw new AuthorizationException('هذا المرفق ليس من رفعك.');
        }

        // The chat's own list, not the lesson's: a PDF is not a picture and a
        // browser's recording is `audio/webm`. See `MediaLimits`.
        return $this->complete->handle($asset, MediaLimits::chatAllowedMimeTypes($asset->kind));
    }
}
