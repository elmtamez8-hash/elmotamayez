<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Resources;

use App\Modules\Settlement\Data\TeacherStatement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The statement on the wire.
 *
 * Built key by key from a DTO the Action already assembled — there is no query
 * in here and there cannot be one. A Resource runs once per row, so a query
 * inside it is an N+1 by construction; that lesson was paid for in 005 with a
 * `lessons` SELECT per session on two different screens.
 *
 * Amounts leave as minor units beside their currency, never as formatted text:
 * a pre-formatted string is a number the client has to parse back before it can
 * add anything to it, and that parse is where a currency's decimals get guessed.
 */
class TeacherStatementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TeacherStatement $statement */
        $statement = $this->resource;

        return [
            'period' => [
                // Null until a close has created the row. The window is real
                // either way — it is every unit no period has claimed.
                'uuid' => $statement->periodUuid,
                'starts_on' => $statement->startsOn,
                'ends_on' => $statement->endsOn,
                'status' => $statement->status->value,
                'status_label' => $statement->status->label(),
            ],
            'students_count' => $statement->studentsCount,
            'units' => [
                ...$statement->unitCounts,
                // Cast, so a teacher with no work yet gets `{}` and not `[]`.
                // PHP cannot tell an empty map from an empty list, and the client
                // types this as an object — one that arrives as an array is the
                // kind of difference nothing notices until Object.keys() runs.
                'by_type' => (object) $statement->unitsByType,
            ],
            'rates' => SettlementRateResource::collection($statement->rates),
            'pending_rate_request' => $statement->pendingRateRequest === null
                ? null
                : RateChangeRequestResource::make($statement->pendingRateRequest),
            'currency' => $statement->currency,
            'gross_minor' => $statement->grossMinor,
            'deductions' => $statement->deductions,
            'net_minor' => $statement->netMinor,
            'carried_in_minor' => $statement->carriedInMinor,
            // The day the window closes and its total stops moving. A statement
            // with a number and no date is one the teacher has to ask about.
            'next_payout_on' => $statement->endsOn,
        ];
    }
}
