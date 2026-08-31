<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The live scoreboard of one study room (FR-014 · SC-006).
 *
 * ⚠️ THIS PAYLOAD CARRIES DATA, WHICH IS A RECORDED DEPARTURE FROM THIS
 * REPOSITORY'S «AN IDENTIFIER AND NOTHING ELSE» RULE. It is written down in
 * `plan.md › Complexity Tracking` with FOUR conditions, and it stops being
 * justified the moment any one of them stops holding:
 *
 * 1. The board IS the room's content, and it is authorised to everyone inside.
 * 2. A room dies in minutes, so the revocation window the id-only rule protects
 *    is bounded by the room itself rather than by when somebody closes a tab.
 * 3. It is a channel of its OWN name, so nothing else rides on this
 *    authorisation.
 * 4. The QUESTIONS stay on HTTP. The licence stops at the board.
 *
 * The alternative — broadcast «something changed», then let every client fetch —
 * is literally the `ParticipantsPanel` defect: 465 unthrottled requests in two
 * minutes from a room filling up, which is what SC-006's two-second p95 would
 * then be measured against.
 *
 * ⚠️ AND `uuid` IS THE PARTICIPANT ROW'S, NEVER THE USER'S. A user uuid is that
 * person's identifier across the whole platform, and this frame is handed to
 * peers who may be children. The participant uuid dies with the room.
 *
 * ⚠️ NO QUESTION TEXT, NO OPTION, NO CORRECTNESS. Ever.
 *
 * ⚠️ `ShouldBroadcast` AND NOT `...Now`: the publish happens on a worker, so a
 * Reverb outage lands in `failed_jobs` instead of failing the answer that
 * triggered it. The row is what matters; the socket is how it arrives sooner.
 */
class StudyRoomBoardUpdated implements ShouldBroadcast
{
    use Dispatchable;

    /**
     * @param  list<array{uuid: string, name: string, score: int, answered: int}>  $rows
     */
    public function __construct(
        public readonly string $roomUuid,
        public readonly string $endsAt,
        public readonly array $rows,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        /*
        | ⚠️ A PRIVATE CHANNEL, NOT A PRESENCE ONE, AND THE PROTOCOL DECIDES IT.
        | `config/reverb.php` sets `accept_client_events_from => 'members'`, so a
        | whisper is REFUSED on a private channel and ACCEPTED on a presence one:
        | a presence channel here would open an unmoderated direct chat between
        | children inside the room — no `hidden_at`, no `ConversationPolicy`, no
        | ban check, no `chat.moderate`. And there is nothing a member list would
        | add, because the board IS the member list.
        */
        return [new PrivateChannel('study-room-board.'.$this->roomUuid)];
    }

    public function broadcastAs(): string
    {
        return 'board.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'room_uuid' => $this->roomUuid,
            'ends_at' => $this->endsAt,
            'rows' => $this->rows,
        ];
    }
}
