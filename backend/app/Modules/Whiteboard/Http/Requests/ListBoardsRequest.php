<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The boards list's filters (contracts/api.md). */
class ListBoardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:160'],
            'course' => ['nullable', 'uuid'],
            'lesson' => ['nullable', 'uuid'],
            'session' => ['nullable', 'uuid'],
            'mine' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{q: string|null, course: string|null, lesson: string|null, session: string|null, mine: bool} */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? (string) $this->input('q') : null,
            'course' => $this->filled('course') ? (string) $this->input('course') : null,
            'lesson' => $this->filled('lesson') ? (string) $this->input('lesson') : null,
            'session' => $this->filled('session') ? (string) $this->input('session') : null,
            'mine' => $this->boolean('mine'),
        ];
    }
}
