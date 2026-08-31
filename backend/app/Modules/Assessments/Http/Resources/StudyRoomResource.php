<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\StudyRoom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One study room, as the screen must draw it.
 *
 * ⚠️ `state` IS SENT AND THE CLIENT READS IT — it does not derive it. The room's
 * state is a comparison against the SERVER's clock, and a browser whose clock is
 * a minute fast would show «انتهت» over a room still taking answers, or the
 * reverse. The same rule 018 wrote down after a recording was re-derived in
 * TypeScript and became unreachable.
 *
 * ⚠️ AND `requested_count` TRAVELS BESIDE `question_count` ON CREATION. Asking
 * for twenty and getting six is an ANSWER (FR-023), not a failure — but only if
 * the student is told, or they see a room they did not ask for and no reason for
 * it.
 *
 * @mixin StudyRoom
 */
class StudyRoomResource extends JsonResource
{
    public function __construct(StudyRoom $room, private readonly ?int $requested = null)
    {
        parent::__construct($room);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $state = $this->state();

        /*
        | ⚠️ `relationLoaded()` AND NOT `whenLoaded()`. The relation is set by hand
        | — one bulk read for the whole page — and it is set to NULL for a room the
        | reader only hosts. `whenLoaded()` would drop the key in both cases, so
        | the screen could not tell «you host this and never played» from «this
        | payload does not carry your score», and would have to guess.
        */
        $mine = $this->relationLoaded('viewerParticipation')
            ? $this->getRelation('viewerParticipation')
            : null;

        return [
            'uuid' => $this->uuid,
            // The invitation IS the uuid; there is no second code and no invitee
            // list. The limit is eligibility and the ceiling.
            'invite_url' => '/study-rooms/'.$this->uuid,
            'state' => $state->value,
            'state_label' => $state->label(),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'question_count' => (int) $this->question_count,
            'max_participants' => (int) $this->max_participants,
            'duration_minutes' => (int) $this->duration_minutes,
            'concept' => $this->whenLoaded('concept', fn (): array => [
                'uuid' => (string) $this->concept?->uuid,
                'name' => (string) $this->concept?->name,
            ]),
            'host' => [
                'uuid' => (string) $this->host?->uuid,
                // ⚠️ The accessor, and the eager load that feeds it names
                // `first_name` and `last_name` — `users` has no `name` column.
                'name' => (string) $this->host?->name,
            ],
            'requested_count' => $this->when($this->requested !== null, $this->requested),
            // Null for a room the reader only hosts: they own it without playing
            // in it, and an absent key is a state the screen explains.
            'score' => $mine?->score,
            'answered' => $mine?->answered_count,
            'finished_at' => $mine?->finished_at?->toIso8601String(),
        ];
    }
}
