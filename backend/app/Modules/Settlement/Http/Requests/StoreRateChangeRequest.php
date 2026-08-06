<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Requests;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRateChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'session_type' => ['required', Rule::enum(ClassSessionType::class)],
            // Minor units, integer, always. A float here is a rounding error with
            // a teacher's name on it.
            'requested_amount_minor' => ['required', 'integer', 'min:1'],
            // WorkspaceRules, not exists:subjects,id — Laravel's exists rule is a
            // raw query that walks straight past the workspace scope.
            'subject_id' => ['nullable', WorkspaceRules::exists('subjects')],
            'grade_level' => ['nullable', 'string', 'max:32'],
        ];
    }
}
