<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One subscription, as its owner and the platform read it.
 *
 * ⚠️ `ends_on` IS `effective_ends_on`, NOT THE PLAN'S ORIGINAL DATE. A freeze
 * moves the real end, and a card printing the sold date would tell a student to
 * renew before a deadline that has already been extended for them — the same
 * number the expiry notice reads, from the same column, so the screen and the
 * message cannot disagree. The sold date is sent beside it only when the two
 * differ, because «تمّ تمديدها» is the sentence that explains the difference.
 *
 * ⚠️ THE PRICE TRAVELS TO THE PAYER AND THE PLATFORM, NEVER TO THE TEACHER.
 * FR-033 forbids a teacher learning the total a NAMED student paid, and the
 * teacher can legitimately reach this payload for their own workspace's
 * subscribers. `$request->user()` decides, here at the edge of the payload,
 * because that is where the audience is known.
 *
 * @property-read Subscription $resource
 */
class SubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $reader = $request->user();

        $mayReadPrice = $reader !== null
            && ((int) $this->resource->student_user_id === (int) $reader->getKey()
                || $reader->can(Permissions::BILLING_PURCHASE_APPROVE));

        $sold = $this->resource->ends_on;
        $effective = $this->resource->effective_ends_on;
        $extended = ! $effective->isSameDay($sold);

        return [
            'uuid' => $this->resource->uuid,
            'plan_title' => $this->whenLoaded('plan', fn (): ?string => $this->resource->plan?->title),
            'teacher_name' => $this->whenLoaded('workspace', fn (): ?string => $this->resource->workspace?->name),
            'starts_on' => $this->resource->starts_on->toDateString(),
            'ends_on' => $effective->toDateString(),
            // Only when a freeze actually moved it: an identical pair on every
            // row is noise the reader has to compare before learning nothing.
            'sold_ends_on' => $extended ? $sold->toDateString() : null,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'price_minor' => $this->when($mayReadPrice, fn (): int => (int) $this->resource->price_minor),
            'currency' => $this->when($mayReadPrice, fn (): string => (string) $this->resource->currency),
        ];
    }
}
