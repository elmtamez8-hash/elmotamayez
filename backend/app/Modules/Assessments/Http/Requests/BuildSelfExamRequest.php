<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What the student may ask a generated paper for (FR-021).
 *
 * ⚠️ THE CONCEPT IS NOT VALIDATED WITH `exists`, deliberately. `WorkspaceRules::exists()`
 * would tell the caller whether a uuid names a concept in THIS workspace, which
 * is a yes/no oracle over another teacher's taxonomy for anyone who can loop.
 * The Action resolves it inside the workspace and gets zero when it does not
 * belong there, so a wrong uuid produces an empty paper rather than an answer.
 */
class BuildSelfExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The same permission that lets them sit a teacher's exam. Generating a
        // paper writes an attempt, and a reader who may not submit one has no
        // business creating it.
        return $this->user()?->can(Permissions::ATTEMPTS_SUBMIT) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'count' => ['nullable', 'integer', 'min:1', 'max:30'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:180'],
            'concept_id' => ['nullable', 'string'],
            'difficulty' => ['nullable', 'string', 'in:easy,medium,hard'],
        ];
    }
}
