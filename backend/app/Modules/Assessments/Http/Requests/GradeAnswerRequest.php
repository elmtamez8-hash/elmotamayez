<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use App\Modules\Assessments\Support\EssayMarks;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The marks a grader is putting on one essay.
 *
 * ⚠️ THE CEILINGS ARE NOT CHECKED HERE. `max:` on a mark would need the
 * criterion's own value, and the criterion belongs to the question this request
 * has not resolved yet — so the rule would either be wrong or would duplicate
 * the lookup. {@see EssayMarks} owns it, where
 * the seeder and the panel reach it too.
 */
class GradeAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorisation is on the ROW — whose paper, in which workspace — so it
        // belongs to GradingPolicy and runs in the controller, which has the
        // answer resolved.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'marks' => ['required', 'array', 'min:1', 'max:20'],
            'marks.*.criterion_id' => ['nullable', 'integer'],
            'marks.*.points' => ['required', 'numeric', 'min:0', 'max:1000'],
            'marks.*.comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
