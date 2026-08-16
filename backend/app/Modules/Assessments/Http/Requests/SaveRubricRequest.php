<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The mark scheme for one essay question, complete (FR-028).
 *
 * The whole list every time, on the same reasoning as exam items: a partial edit
 * cannot express a deletion, and the sum rule is a statement about the SET.
 */
class SaveRubricRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'criteria' => ['present', 'array', 'max:20'],
            'criteria.*.label' => ['required', 'string', 'max:255'],
            'criteria.*.max_points' => ['required', 'numeric', 'min:0.25', 'max:1000'],
            'criteria.*.order' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
