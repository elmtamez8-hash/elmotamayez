<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ⚠️ NO `exists:` RULE ON EITHER UUID, and that is deliberate.
 *
 * `exists:courses,uuid` answers "does this row exist anywhere on the platform",
 * which on a tenant-owned table is a raw query past every scope — and here it
 * would also be an identity probe: a 422 for an unknown uuid and a 403 for a
 * known one tells a stranger which courses exist. Both lookups happen in the
 * controller and the guard is participation, proved inside the Action, so the
 * two answers are indistinguishable from outside.
 *
 * Shape only, like every FormRequest here. Whether this course still sells and
 * whether this student may hold more credits are FR-021ط and FR-021ي, and both
 * live in PurchaseCredits — which the panel and any future console command also
 * reach.
 */
class PurchaseCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'course' => ['required', 'string', 'uuid'],
            'package' => ['required', 'string', 'uuid'],
        ];
    }
}
