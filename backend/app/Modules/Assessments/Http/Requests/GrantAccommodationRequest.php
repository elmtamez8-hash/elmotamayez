<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A standing arrangement for one student (FR-053 · FR-055).
 *
 * Same reasoning as the extension: the student uuid carries no `exists` rule,
 * because that rule is an identity oracle. The reason is required here as well
 * as in the Action — FR-055 asks who granted it and why, and a blank reason is a
 * record that answers the first question and not the second.
 */
class GrantAccommodationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::ACCOMMODATIONS_MANAGE) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'student_uuid' => ['required', 'uuid'],
            'extra_time_pct' => ['nullable', 'integer', 'min:0', 'max:200'],
            'extended_days' => ['nullable', 'integer', 'min:0', 'max:30'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
