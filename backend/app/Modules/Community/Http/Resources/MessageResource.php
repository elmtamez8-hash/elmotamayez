<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Resources;

use App\Modules\Community\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message on the wire.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'body' => $this->body,
            'sender_uuid' => $this->whenLoaded('sender', fn () => $this->sender?->uuid),
            /*
            | ⚠️ `whenLoaded`, AND THE QUERY BUDGET TEST ASSERTS THIS KEY IS
            | PRESENT. Dropping the eager load in `ReadMessages` produces no N+1
            | here — the key simply disappears and the page gets CHEAPER, which a
            | test measuring queries alone reports as an improvement while the
            | screen lists messages with nobody's name on them.
            */
            'sender_name' => $this->whenLoaded('sender', fn () => $this->sender?->name),
            'is_helpful' => (bool) $this->is_helpful,
            /*
            | ⚠️ NULL IS THE ANSWER FOR MOST SENDERS, AND IT IS AN ANSWER. A
            | teacher and an assistant are on no leaderboard at all; a student
            | who joined this morning has no row either, because the boards roll
            | up nightly. A zero here reads as «المركز ٠» beside the teacher's own
            | name in front of the class. Filled by `ChatRankStamper` for public
            | rooms and left null everywhere else — a badge in a one-to-one thread
            | is a score attached to a private question.
            */
            'sender_rank' => $this->senderRank,
            'sender_level' => $this->senderLevel,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
