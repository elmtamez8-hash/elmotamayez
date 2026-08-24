<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the client declares before uploading (`FR-060`).
 *
 * ⚠️ EVERY NUMBER HERE IS A CLAIM, AND THE ACTION TREATS IT AS ONE. Validation
 * catches a malformed request; the ceiling that counts is checked again on
 * completion against the file that actually arrived — `MediaLimits` exists
 * because those two checks used to be different numbers, and the second one was
 * the only real one.
 */
class RequestChatAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The real guard is `ConversationPolicy::post()` inside the Action, which
        // also covers the ban. Authorising here would be a second answer to the
        // same question, and the two would drift.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['image', 'voice'])],
            'filename' => ['required', 'string', 'max:255'],
            'size_bytes' => ['nullable', 'integer', 'min:1'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
