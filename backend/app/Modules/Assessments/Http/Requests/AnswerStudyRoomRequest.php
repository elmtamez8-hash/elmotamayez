<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One answer inside a study room.
 *
 * ⚠️ `question_id` AND NOT `order`. The uniqueness that makes a double tap a
 * refusal rather than a second scored answer is `unique(attempt_id, question_id)`
 * — `order` is a display number, and addressing by it would mark the student
 * against a different question with the index blind to it.
 *
 * ⚠️ AND `option_ids` MAY BE EMPTY. «I do not know» is an answer and the
 * strongest evidence of a gap: it is marked wrong and enters the mistake
 * notebook. Requiring at least one option leaves the student no way to say it,
 * which on a timed board means guessing instead.
 */
class AnswerStudyRoomRequest extends FormRequest
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
            // No `exists`: the option belongs to a question the Action has
            // already proved is on this participant's paper, and the frozen
            // snapshot is what it compares against — an id from anywhere else
            // simply does not match.
            'option_ids.*' => ['integer', 'min:1'],
        ];
    }
}
