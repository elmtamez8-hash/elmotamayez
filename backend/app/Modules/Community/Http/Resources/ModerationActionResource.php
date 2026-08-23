<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Resources;

use App\Modules\Community\Models\ModerationAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One decision, as the moderator's own screen reads it back.
 *
 * ⚠️ THE SUBJECT'S ID IS NOT SENT. A moderation row names a person, and the
 * sequential id is the one handle this product never exposes; the panel already
 * knows who it acted on, because it is what asked.
 *
 * @mixin ModerationAction
 */
class ModerationActionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'verdict' => $this->verdict->value,
            'subject_type' => $this->subject_type,
            'reason' => $this->reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'actor_name' => $this->whenLoaded('actor', fn () => $this->actor?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
