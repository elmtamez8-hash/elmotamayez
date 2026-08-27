<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

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
    /**
     * ⚠️ THE ENROLMENT IS THE AUTHORISATION, AND A PERMISSION HERE REFUSED EVERY
     * REAL STUDENT ON THE PLATFORM.
     *
     * This asked `can(ATTEMPTS_SUBMIT)`, which reads as exactly right and is
     * dead: spatie runs in TEAM MODE with `team_id = workspace_id`, and a student
     * is a member of no workspace — nothing on their path writes
     * `users.last_workspace_id` — so the context is null, the team id is null,
     * and **every `can()` for them is false**. `POST /practice/exams` answered
     * `403 This action is unauthorized` to every student who ever opened «درّب
     * نفسك»; measured on a fixture built without a seeder, 2026-08-27.
     *
     * No test could see it: `addWorkspaceMember()` and the demo seeders both
     * stamp that column, so the suite was measuring a person the product does not
     * create. It is the `belongsToCurrentWorkspace()` defect of 2026-08-26
     * reached through a FormRequest, and the fix is its fix — the ownership
     * branch, which here is the enrolment `BuildSelfExam` already reads to pick
     * the questions. A paper drawn from courses the student is actively enrolled
     * in cannot be a paper they are not entitled to.
     *
     * The sibling endpoint `POST /practice/from-mistakes` has always been correct
     * on this and carries no permission check either.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
            | ⚠️ THE TEACHER IS A UUID WITH NO `exists` RULE, the `?course=` idiom
            | from the assignment list. Laravel's `exists` is a raw query with no
            | tenant condition, so it would pass for a workspace the reader does
            | not study with; the controller resolves it against the reader's own
            | enrolments instead, and a uuid that resolves to nothing refuses
            | rather than quietly widening the pool.
            */
            'teacher' => ['nullable', 'uuid'],
            'course' => ['nullable', 'uuid'],
            'subject' => ['nullable', 'uuid'],
            'count' => ['nullable', 'integer', 'min:1', 'max:30'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:180'],
            'concept_id' => ['nullable', 'string'],
            'difficulty' => ['nullable', 'string', 'in:easy,medium,hard'],
        ];
    }
}
