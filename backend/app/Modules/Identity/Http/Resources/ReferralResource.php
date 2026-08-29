<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Referral;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One invitation, as its SENDER may see it (spec 011 · FR-019).
 *
 * ⚠️ THE INVITED PERSON IS NOT NAMED, AND THAT IS THE WHOLE OF THE PRIVACY
 * DECISION HERE. A referrals list carrying names and email addresses is a
 * contact list assembled out of other people's signups — and the inviter
 * already knows who they invited. What they do not know, and what this answers,
 * is whether it paid.
 *
 * ⚠️ AND `flagged_reason` STAYS OFF THE WIRE. It is written for a human
 * reviewing abuse (FR-022), and handing the suspected party the exact rule they
 * tripped is a tuning guide for the next attempt.
 *
 * @mixin Referral
 */
class ReferralResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // The date they signed up, which is what an inviter recognises a row
            // by when they have sent several.
            'invited_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
