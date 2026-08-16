<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A hand-in (FR-044 · FR-048).
 *
 * ⚠️ THE FILE RULES ARE THE ACCESS CONTROL'S FIRST HALF. A submission upload is
 * an authenticated stranger writing bytes onto our disk; `mimes` and `max` are
 * what stop it being an arbitrary-file host with a login page.
 */
class SubmitAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Row-level: whose assignment, in which workspace. AssignmentPolicy owns
        // it and runs in the controller, which has the assignment resolved.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'answer_text' => ['nullable', 'string', 'max:20000'],
            'file' => ['nullable', 'file', 'mimes:pdf,doc,docx,png,jpg,jpeg,txt,zip', 'max:10240'],
        ];
    }
}
