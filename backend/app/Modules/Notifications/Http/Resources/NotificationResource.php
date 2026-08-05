<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'type_label' => $this->type()->label(),
            // The stored text, not a re-render: an old notification keeps the
            // wording it was sent with even after its template changes.
            'title' => $this->title_ar,
            'body' => $this->body_ar,
            'action_url' => $this->action_url,
            'subject' => $this->whenLoaded('subject', fn () => $this->subject === null ? null : [
                'uuid' => $this->subject->uuid,
                'name' => $this->subject->name,
            ]),
            'workspace' => $this->whenLoaded('workspace', fn () => $this->workspace === null ? null : [
                'uuid' => $this->workspace->uuid,
                'name' => $this->workspace->name,
            ]),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
