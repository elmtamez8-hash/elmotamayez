<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Requests;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateSessionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'teacher_profile_id' => ['required', WorkspaceRules::exists('teacher_profiles')],
            'from' => ['required', 'date'],
            // Bounded so one request cannot generate a decade of sessions and
            // spend the rest of the afternoon doing it.
            'to' => ['required', 'date', 'after:from', 'before:'.now()->addYear()->toDateString()],
            'slot_uuids' => ['sometimes', 'array'],
            'slot_uuids.*' => ['uuid'],
            'seats_total' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'type' => ['sometimes', Rule::enum(ClassSessionType::class)],
            'title' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
