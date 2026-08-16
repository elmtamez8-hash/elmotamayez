<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A later date for one student (FR-047).
 *
 * ⚠️ THE STUDENT IS A UUID WITH NO `exists` RULE, deliberately. `exists:users,uuid`
 * answers "is this a real person" — a yes/no oracle over every account on the
 * platform for anyone who can loop. The Action asks EnrollmentDirectory instead,
 * and the controller answers 404 whether the uuid names nobody or names somebody
 * this workspace does not teach.
 *
 * `after:now` is here so the Action's remaining refusal is the enrolment one
 * alone — otherwise the controller could not turn it into 404 without hiding a
 * legitimate validation error behind a "not found".
 */
class GrantExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'student_uuid' => ['required', 'uuid'],
            'until' => ['required', 'date', 'after:now'],
        ];
    }
}
