<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorisation is the policy, asked inside the Action against the
        // conversation itself — there is nothing to decide from the body alone.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
            | ⚠️ NO `exists:` RULE ON EITHER. Laravel's `exists` is a raw query
            | with no tenant condition, so a rule here would answer «this uuid is
            | real» to anybody who guessed one — an identity probe answered by the
            | validator, before a single policy runs. Both are resolved inside the
            | Action, where a miss is a 404 that says nothing about which of the
            | two was wrong.
            */
            'workspace' => ['required', 'string', 'uuid'],
            'student' => ['nullable', 'string', 'uuid'],
        ];
    }
}
