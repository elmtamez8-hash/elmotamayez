<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\CreditBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * One course's credits, as the student sees them.
 *
 * ⚠️ CREDITS ONLY — not one figure of money anywhere (FR-021د). The price of a
 * credit is built from the teacher's approved settlement rate plus two platform
 * constants, so a student who knows their own price can solve for the constants
 * and then read every other teacher's rate off any published total. Keeping money
 * out of this payload is not modesty about pricing; it is what stops the whole
 * settlement side leaking through arithmetic.
 *
 * `is_withheld` is derived and sent as a boolean rather than as a hold record,
 * because there is no hold record — see CreditLedger.
 *
 * @property CreditBalance $resource
 */
class CreditBalanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $withheld = $this->resource->getAttribute('is_withheld');

        // Refused rather than defaulted. `(bool) null` is false, so a caller who
        // forgets WithholdingReader::stamp() would show a withheld student as
        // unblocked — silently, and in the one direction that costs money. The
        // predicate is not recomputed here either: a second copy of it is a copy
        // that drifts (FR-013).
        if (! is_bool($withheld)) {
            throw new LogicException(
                'CreditBalanceResource needs a balance stamped by WithholdingReader::stamp().',
            );
        }

        return [
            'uuid' => $this->resource->uuid,
            'course' => [
                'uuid' => $this->resource->course->uuid,
                'title' => $this->resource->course->title,
                'teacher_name' => $this->resource->workspace->name,
            ],
            'purchased_credits' => $this->resource->purchased_credits,
            'consumed_credits' => $this->resource->consumed_credits,
            'remaining_credits' => $this->resource->remaining_credits,
            'credit_limit_credits' => $this->resource->credit_limit_credits,
            'is_withheld' => $withheld,
            /*
            | ⚠️ SENT, NOT LEFT TO THE BROWSER. The client cannot compute this and
            | never could: the deficit is the distance to the EFFECTIVE floor,
            | which depends on the billing mode, an open exam window and a current
            | terms consent — none of which is in this payload, and none of which
            | belongs in it. BalanceSummary.tsx derived it from `remaining` and
            | `credit_limit` alone and was therefore wrong for every indebted
            | student the day new terms are published, and for everyone during an
            | exam window.
            |
            | Zero when nothing is withheld, so the card has no branch to get
            | wrong. It is a count of sessions, like every other number here —
            | there is no money in it to leak.
            */
            'credits_needed' => (int) ($this->resource->getAttribute('credits_needed') ?? 0),
        ];
    }
}
