<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlaceLegalHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
            | No `exists` rule, for the reason `StoreDataRequestRequest` states: a
            | distinct validation error confirms that a submitted uuid belongs to a
            | real account. This endpoint is behind a platform permission held by a
            | handful of people, so the exposure is small — but two endpoints
            | answering the same question two ways is how the rule stops being one.
            */
            'student_uuid' => ['required', 'string', 'uuid'],

            // A hold with no stated reason is an erasure blocked by nobody, and
            // `ReleaseLegalHold` gives the next officer nothing to weigh.
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function subjectUuid(): string
    {
        return (string) $this->input('student_uuid');
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
