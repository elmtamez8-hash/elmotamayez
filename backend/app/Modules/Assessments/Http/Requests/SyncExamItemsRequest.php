<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use App\Modules\Assessments\Models\Exam;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The exam's questions, all of them, in order.
 *
 * `items` is `present` rather than `required`: an empty array is a legitimate
 * instruction — the teacher emptied the exam — and `required` rejects `[]`,
 * which would make clearing an exam impossible through the endpoint that exists
 * for setting its contents.
 */
class SyncExamItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $exam = $this->route('exam');

        return $exam instanceof Exam
            && ($this->user()?->can('manageQuestions', $exam) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:200'],
            'items.*.uuid' => ['required', 'uuid', WorkspaceRules::exists('questions', 'uuid')],
            // Null means "whatever the bank says". Absent means the same thing;
            // a zero does not — it is a question worth nothing, which is a
            // decision rather than a default.
            'items.*.points_override' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
