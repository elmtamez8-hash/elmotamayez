<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One name in the «who am I paying for» picker.
 *
 * ⚠️ TWO FIELDS, AND THE ABSENT ONES ARE THE POINT. A guardian is authorised for
 * PAYMENTS on this child and for nothing else implied by it — an email, a phone
 * number, a school year or a balance here would be six other permissions granted
 * by the picker that exists to spend money. `GuardianPermission` is per-subject
 * precisely so that «may pay» does not read as «may see everything».
 *
 * @property User $resource
 */
class PurchaseBeneficiaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->resource->uuid,
            'name' => $this->resource->name,
        ];
    }
}
