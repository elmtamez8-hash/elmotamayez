<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One answer inside a running session.
 *
 * ⚠️ `question_id` AND NOT `order`. The uniqueness that makes a double tap a
 * refusal rather than a second scored answer is `unique(attempt_id, question_id)`;
 * `order` is assigned by reading `max(order) + 1`, so two rows can share a number
 * and `{"order": 3}` would mark the student against a different question — with
 * the unique index blind to it, because the two questions differ.
 *
 * ⚠️ AND `option_ids` MAY BE EMPTY. «I do not know» is an answer, and the
 * strongest evidence of a gap: it is marked wrong, it enters the mistake
 * notebook, and it moves the ladder down like any other wrong answer. Requiring
 * at least one option would leave the student with no way to say it.
 */
class AnswerAdaptiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question_id' => ['required', 'integer', 'min:1'],
            'option_ids' => ['present', 'array', 'max:20'],
            // No `exists`: an option belongs to a question the Action has already
            // proved is this student's, and the snapshot is what it compares
            // against — an id from anywhere else simply does not match.
            'option_ids.*' => ['integer', 'min:1'],
        ];
    }
}
