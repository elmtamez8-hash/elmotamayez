<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reviewing a course's promotional video (018 · FR-006).
 *
 * ⚠️ THE PERMISSION IS CHECKED HERE **AND** IN THE ACTION, and that is not
 * duplication. Filament reaches the action with no form behind it, so a rule
 * living only here is a rule the admin panel walks around — and one living only
 * in the action would answer a 403 as a 500-shaped exception at the API door.
 */
class ReviewPromoVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::MARKETPLACE_PROMO_REVIEW) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in([Course::PROMO_APPROVED, Course::PROMO_REJECTED])],
            /*
            | Required with a rejection, and the action refuses one without it
            | too: a refusal the teacher cannot act on is a refusal they answer
            | by pasting the same link again.
            */
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:decision,'.Course::PROMO_REJECTED],
        ];
    }
}
