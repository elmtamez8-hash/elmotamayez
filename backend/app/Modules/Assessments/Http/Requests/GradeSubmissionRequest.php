<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The mark before lateness is charged.
 *
 * The ceiling is not `max:` here: it is the ASSIGNMENT's own points, which this
 * request has not resolved. GradeSubmission owns that comparison, where the
 * panel and any later importer reach it too.
 */
class GradeSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'score' => ['required', 'numeric', 'min:0', 'max:1000'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
