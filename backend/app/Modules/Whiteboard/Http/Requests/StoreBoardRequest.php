<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Requests;

use App\Modules\Whiteboard\Enums\BoardBackground;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new board. Uuids on the wire, checked with `WorkspaceRules::exists()` — Laravel's
 * `exists` rule is a raw query that ignores the workspace scope. `BoardPlacement`
 * re-checks inside the Action (the lesson's course, the assistant's scope).
 */
class StoreBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'background' => ['sometimes', Rule::enum(BoardBackground::class)],
            'course' => ['nullable', 'uuid', WorkspaceRules::exists('courses', 'uuid')],
            'lesson' => ['nullable', 'uuid', WorkspaceRules::exists('lessons', 'uuid')],
        ];
    }
}
