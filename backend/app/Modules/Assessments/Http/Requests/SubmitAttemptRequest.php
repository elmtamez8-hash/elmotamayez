<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitAttemptRequest extends FormRequest
{
    /**
     * ⚠️ THIS ASKED `can(ATTEMPTS_SUBMIT)`, AND IT REFUSED EVERY REAL STUDENT ON
     * THE PLATFORM — the third time this tree has shipped the same defect.
     *
     * `ATTEMPTS_SUBMIT` is a permission on the STUDENT ROLE, and spatie runs in
     * TEAM MODE with `team_id = workspace_id`. A real student is a member of no
     * workspace — nothing on their path writes `users.last_workspace_id` — so the
     * context is null, the team id is null, and **every `can()` for them is
     * false**. Measured on 2026-08-30 with a fixture built the way the product
     * actually creates a student: `POST /exams/{exam}/attempts` answered **201**
     * and `POST /attempts/{uuid}/submit` answered **403** to the same person.
     *
     * ⚠️ AND IT COST MORE THAN A REFUSAL. `StartAttempt` had already written the
     * attempt row, so the student spent one of `max_attempts` on a paper they
     * could never hand in — then the second, then the third.
     *
     * The authorisation is OWNERSHIP, and it was already there: the very next
     * thing this route does is `authorize('submit', $attempt)`, and
     * `AttemptPolicy::submit()` allows only `student_user_id === $user->getKey()`.
     * This line was stopping the request from reaching a correct guard.
     *
     * Precedent, same defect, other endpoints: `BuildSelfExamRequest` (fixed
     * 2026-08-27) and `EnrollmentPolicy::view()`'s unreachable ownership branch
     * (fixed 2026-08-26). `POST /practice/from-mistakes` has always been right
     * and carries no permission check either.
     */
    public function authorize(): bool
    {
        return true;
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
