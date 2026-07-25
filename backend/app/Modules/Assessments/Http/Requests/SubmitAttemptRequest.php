<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class SubmitAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::ATTEMPTS_SUBMIT) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer'],
            // An empty selection is a valid answer: the question is graded as zero.
            // Rejecting it would force clients to drop skipped questions silently.
            'answers.*.selected_option_ids' => ['present', 'array'],
            'answers.*.selected_option_ids.*' => ['integer'],
        ];
    }
}
