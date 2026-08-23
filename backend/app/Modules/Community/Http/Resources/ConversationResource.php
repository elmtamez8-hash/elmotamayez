<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Resources;

use App\Modules\Community\Models\Conversation;
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
}
