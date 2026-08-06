<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A batch of remarks: the teacher fills the register in one pass, not one
 * request per student.
 *
 * The student is named by uuid and checked against the register inside the
 * Action, not by an `exists` rule here — a uuid that exists is not the same
 * question as a uuid that belongs to this session, and only the second one
 * matters.
 */
class SubmitFeedbackRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.student_uuid' => ['required', 'uuid'],
            'entries.*.rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'entries.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
