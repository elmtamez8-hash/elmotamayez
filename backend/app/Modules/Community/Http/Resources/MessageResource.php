<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Resources;

use App\Modules\Community\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

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
            'attachment' => $this->attachment(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The picture or the voice note, with a link that works in an `<img>`
     * (`FR-060` · `FR-061`).
     *
     * ⚠️ THE URL IS MINTED HERE, AND THAT IS THE AUTHORISATION. This Resource
     * renders only inside a response `ConversationPolicy::view()` has already
     * admitted, so the signature is handed to a reader who was entitled to it at
     * the moment it was made. It expires in fifteen minutes — long enough to load
     * a thread and scroll it, short enough that a link pasted elsewhere is dead
     * before it travels.
     *
     * ⚠️ AND IT NAMES NO PROVIDER AND NO STORAGE PATH. `provider_asset_id` is the
     * one field `FR-011` forbids in a payload — the same rule that makes
     * `PlaybackGrantResource` send our own route rather than the manifest's url.
     *
     * ⚠️ AND `whenLoaded` IS DELIBERATE: without the eager load in `ReadMessages`
     * this is one query PER MESSAGE, on a page of fifty.
     *
     * @return array{kind: string, url: string, duration_seconds: int|null}|null
     */
    private function attachment(): ?array
    {
        if (! $this->relationLoaded('mediaAsset') || $this->mediaAsset === null) {
            return null;
        }

        $asset = $this->mediaAsset;

        return [
            // What the client needs to choose a renderer, derived from the mime
            // type rather than from a column of its own: the pipeline already
            // records what actually arrived, and a second field would be a second
            // answer that can disagree with the bytes.
            'kind' => str_starts_with((string) $asset->mime_type, 'audio/') ? 'voice' : 'image',
            'url' => URL::temporarySignedRoute(
                'chat.attachment',
                now()->addMinutes(15),
                ['message' => $this->uuid],
            ),
            'duration_seconds' => $asset->duration_seconds,
        ];
    }
}
