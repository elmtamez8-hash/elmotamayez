<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePeriodicReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The policy check and the enrolment check both need the workspace and the
        // student, which the controller and the Action resolve.
        return true;
    }

    /**
     * ⚠️ NO `exists:users,uuid` ON THE STUDENT. It answers a different question —
     * «does this account exist» rather than «is this your student» — and answering
     * the first one is the identity probe NFR-001أ forbids. `SubmitPeriodicReview`
     * asks `EnrollmentDirectory` instead, and refuses both cases with one sentence.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'student_uuid' => ['required', 'uuid'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'commitment' => ['required', 'integer', 'between:1,5'],
            'participation' => ['required', 'integer', 'between:1,5'],
            'homework' => ['required', 'integer', 'between:1,5'],
            'improvement' => ['required', 'integer', 'between:1,5'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
