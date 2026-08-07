<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Resources;

use App\Modules\Settlement\Models\TeacherPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TeacherPayout
 *
 * Money that left, with the string that identifies it in a bank statement.
 *
 * The reference is the field the teacher actually needs: an amount they cannot
 * match against a transfer is an amount they have to ask about.
 */
class TeacherPayoutResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'method' => $this->method,
            'executed_at' => $this->executed_at->toIso8601String(),
        ];
    }
}
