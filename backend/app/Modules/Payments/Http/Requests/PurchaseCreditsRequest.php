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

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['student_uuid' => 'الطالب'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'course' => ['required', 'string', 'uuid'],
            'package' => ['required', 'string', 'uuid'],
            /*
            | Who this is being bought FOR — absent means «me», which is exactly
            | what every request sent before spec 031 and therefore what they all
            | keep meaning.
            |
            | ⚠️ `student_uuid`, NEVER `student`: this is the name spec 029 shipped
            | on `PurchaseSubscriptionRequest`, for the same field, resolved by the
            | same class, in this same module. Two names for one thing in one
            | module is where a divergence starts.
            |
            | And no `exists:` here either, for the reason written above: it would
            | answer «this uuid names a real account» to anyone who guesses one.
            | The guardianship is asked in `PurchaseBeneficiary`, whose refusal is
            | one sentence for all three ways of being wrong.
            */
            'student_uuid' => ['sometimes', 'nullable', 'uuid'],
            // Shape only, as above. Whether the code exists, is live, is in
            // scope and has a place left is `DiscountResolver`'s to answer with
            // one uniform sentence — an `exists:` rule here would be the oracle
            // that sentence exists to close.
            'coupon_code' => ['nullable', 'string', 'max:32'],
        ];
    }
}
