<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The answers so far on a timed sitting — `SubmitAttemptRequest`'s shape, but
 * an empty list is a valid save (every answer cleared).
 *
 * Ownership is the authorisation, asked by the controller through
 * `AttemptPolicy::submit()` — never a permission, for the reason
 * `SubmitAttemptRequest::authorize()` records.
 */
class SaveAttemptDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'answers' => ['present', 'array', 'max:500'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.selected_option_ids' => ['present', 'array'],
            'answers.*.selected_option_ids.*' => ['integer'],
            'answers.*.answer_text' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
