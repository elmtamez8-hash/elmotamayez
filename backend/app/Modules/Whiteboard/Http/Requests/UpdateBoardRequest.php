<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Requests;

use App\Modules\Whiteboard\Enums\BoardBackground;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A board's name, background or placement. Uuids on the wire, checked with `WorkspaceRules::exists()` — Laravel's
 * `exists` rule is a raw query that ignores the workspace scope. `BoardPlacement`
 * re-checks inside the Action (the lesson's course, the assistant's scope).
 */
class UpdateBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'filled', 'string', 'max:160'],
            'background' => ['sometimes', Rule::enum(BoardBackground::class)],
            'course' => ['nullable', 'uuid', WorkspaceRules::exists('courses', 'uuid')],
            'lesson' => ['nullable', 'uuid', WorkspaceRules::exists('lessons', 'uuid')],
            // Story 7: the live class this board is opened from.
            'class_session' => ['nullable', 'uuid', WorkspaceRules::exists('class_sessions', 'uuid')],
        ];
    }
}
