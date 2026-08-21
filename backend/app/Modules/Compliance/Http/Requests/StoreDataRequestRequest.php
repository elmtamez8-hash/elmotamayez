<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Requests;

use App\Models\User;
use App\Modules\Compliance\Enums\DataRequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDataRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The real gate is the policy and the Action's guardian check. A `false`
        // here would answer 403 for a malformed payload too, which is a different
        // fact about a different problem.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
            | ⚠️ NO `exists` RULE, AND ITS ABSENCE IS THE FEATURE. `exists` answers
            | a different question out loud: submit a uuid and a distinct validation
            | error confirms it belongs to a real account. `CreateDataRequest`
            | refuses "no such person" and "not yours" with one message for exactly
            | that reason, and a rule here would undo it before the Action runs.
            | `LinkGuardian` already made this decision; this follows it.
            */
            'student_uuid' => ['nullable', 'string', 'uuid'],

            'type' => ['required', 'string', Rule::in(array_column(DataRequestType::cases(), 'value'))],
        ];
    }

    /** The subject's uuid — the caller's own when none was named. */
    public function subjectUuid(User $caller): string
    {
        $uuid = $this->input('student_uuid');

        return is_string($uuid) && $uuid !== '' ? $uuid : (string) $caller->uuid;
    }

    public function type(): DataRequestType
    {
        return DataRequestType::from((string) $this->input('type'));
    }
}
