<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Resources;

use App\Modules\Community\Models\Conversation;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One thread on the list screen.
 *
 * ⚠️ THE COUNTERPART IS A NAME, NEVER AN EMAIL OR A PHONE NUMBER. This payload
 * reaches a student, and it names the other people in their teacher's workspace;
 * a contact detail here would be a directory of the teacher's staff handed to
 * everyone who ever asked a question.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind->value,
            'student_name' => $this->whenLoaded('student', fn () => $this->student?->name),
            // The subject a ban is written against (`FR-064`). A uuid and no
            // contact detail: this payload reaches the student too, and the rule
            // in this class's docblock is that it carries names and nothing that
            // could be used to reach a person outside the product.
            'student_uuid' => $this->whenLoaded('student', fn () => $this->student?->uuid),

            /*
            | ⚠️ «WHO AM I TALKING TO» HAS TWO ANSWERS, AND THE LIST USED TO SEND
            | ONLY THE TEACHER'S. `student_name` is the counterpart for the
            | teacher's side and the reader's OWN name for the student's — so
            | every row on a student's screen was titled with their own name, on
            | a list whose entire purpose is telling threads apart. It shipped
            | because every fixture that exercised the list was a teacher's.
            |
            | The student's counterpart is the WORKSPACE, not a user: the thread
            | carries no teacher column, the teacher's side being derived from
            | membership, and an assistant may be the one who actually replies.
            | The workspace name is also the name the student chose the teacher by
            | in the marketplace, which is the one they will recognise.
            */
            'counterparty_name' => $this->counterpartyName($request),

            /*
            | Whether THIS reader may hide a message or ban the sender here.
            | Viewer-level rather than row-level on purpose: it is a permission
            | inside one workspace, so it costs no query per row — spatie has the
            | permission set cached for the request by the time the first row is
            | serialised, and a policy call per conversation would be an N+1 by
            | construction on a list of two hundred.
            */
            'can_moderate' => $this->readerMayModerate($request),

            /*
            | Whether the student is banned right now, so the control can offer
            | the action NOT yet taken. Stamped in bulk by `ListConversations`;
            | false for anyone who may not moderate, which is also the value a
            | student's own payload carries — one person's standing is not
            | another's business.
            */
            'student_banned' => $this->readerMayModerate($request) && $this->studentBanned,
            'last_message' => $this->whenLoaded(
                'lastMessage',
                fn () => $this->lastMessage === null
                    ? null
                    : [
                        'uuid' => $this->lastMessage->uuid,
                        'body' => $this->lastMessage->body,
                        'sender_name' => $this->lastMessage->relationLoaded('sender')
                            ? $this->lastMessage->sender?->name
                            : null,
                        'created_at' => $this->lastMessage->created_at?->toIso8601String(),
                    ],
            ),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The name of the OTHER side, from this reader's position.
     *
     * A public room has no counterpart at all — it is a class, not a person — so
     * it answers null and the screen falls back to the room's own heading.
     */
    private function counterpartyName(Request $request): ?string
    {
        if ($this->kind->isPublic()) {
            return null;
        }

        $reader = $request->user();

        if ($reader !== null && (int) $this->student_user_id === (int) $reader->getKey()) {
            return $this->relationLoaded('workspace') ? $this->workspace?->name : null;
        }

        return $this->relationLoaded('student') ? $this->student?->name : null;
    }

    private function readerMayModerate(Request $request): bool
    {
        $reader = $request->user();

        if ($reader === null) {
            return false;
        }

        // The student is never a moderator of their own thread, and asking spatie
        // for them is asking about a person with no team id and therefore no
        // roles — a false that costs a lookup to reach.
        if ((int) $this->student_user_id === (int) $reader->getKey()) {
            return false;
        }

        return $reader->hasPermissionTo(Permissions::CHAT_MODERATE);
    }
}
