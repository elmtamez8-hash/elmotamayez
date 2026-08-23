<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Requests;

use App\Modules\Community\Enums\ModerationVerdict;
use App\Modules\Community\Models\ModerationAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The permission is asked inside the Action, about the workspace the
        // SUBJECT belongs to — which cannot be known from the body alone.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'verdict' => ['required', Rule::enum(ModerationVerdict::class)],
            'subject_type' => ['required', Rule::in([ModerationAction::SUBJECT_USER, ModerationAction::SUBJECT_MESSAGE])],
            // ⚠️ No `exists:` rule: it is a raw query with no tenant condition,
            // so it answers «this uuid is real» to anybody who guesses one,
            // before a policy runs. Resolved inside the Action.
            'subject_uuid' => ['required', 'string', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
