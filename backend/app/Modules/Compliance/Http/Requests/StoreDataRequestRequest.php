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

            /*
            | ⚠️ ERASURE IS NOT OFFERED YET, AND A 422 IS THE HONEST ANSWER.
            | `ExecuteDataErasure` lands with US4; accepting the type now would
            | open a request nothing executes — a row a person believes is deleting
            | their data while it sits `pending` for ever. The screen says
            | «متاح قريباً» and this is the same sentence at the door.
            |
            | Named as a list rather than as `cases()` minus one, so adding a fourth
            | type does not silently open it.
            */
            'type' => ['required', 'string', Rule::in([
                DataRequestType::Access->value,
                DataRequestType::Export->value,
            ])],
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
