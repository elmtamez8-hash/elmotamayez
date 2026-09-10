<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationCategory;
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
            /*
            | The SUBJECT this row belongs to — «الحصص والمواعيد», not
            | «تسجيل الحصة غير متاح».
            |
            | ⚠️ SENT RATHER THAN DERIVED IN THE BROWSER, and that is the whole
            | reason it is here. The screen needs it twice — to pick the row's
            | icon and to move one tab's counter when the row is marked read —
            | and a client-side map from forty-eight types to seven subjects
            | would be a second copy of a classification the server owns. It goes
            | stale the day a type is re-filed, silently, because a row with the
            | wrong icon still renders.
            |
            | Null for a type nobody classified: the row still arrives and still
            | reads, it simply belongs to no tab.
            */
            'category' => $this->categoryPayload(),
            // The stored text, not a re-render: an old notification keeps the
            // wording it was sent with even after its template changes.
            'title' => $this->title,
            'body' => $this->body,
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

    /** @return array{key: string, label: string}|null */
    private function categoryPayload(): ?array
    {
        $category = NotificationCategory::byType()[(string) $this->type] ?? null;

        return $category === null ? null : [
            'key' => $category->value,
            'label' => $category->label(),
        ];
    }
}
