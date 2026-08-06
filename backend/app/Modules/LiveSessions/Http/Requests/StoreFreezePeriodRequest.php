<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `student_uuid` absent means the whole workspace — every student of this
 * teacher (FR-039). It is checked against `users` and then against an actual
 * booking inside the Action, because a uuid that exists is a different question
 * from a uuid this teacher may freeze.
 */
class StoreFreezePeriodRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'student_uuid' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
