<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A grade already told to the student is being changed (FR-032).
 *
 * ⚠️ THE REASON IS `required`, AND AGAIN IN THE ACTION. A grade that moves with
 * nothing behind it is the one the student appeals and nobody can answer; the
 * duplicate rule is deliberate, because a revision written by the panel or a
 * seeder never passes through this class.
 */
class ReviseGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'marks' => ['required', 'array', 'min:1', 'max:20'],
            'marks.*.criterion_id' => ['nullable', 'integer'],
            'marks.*.points' => ['required', 'numeric', 'min:0', 'max:1000'],
            'marks.*.comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
