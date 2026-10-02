<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Requests;

/** Every page of the board, in the new order. Which set it must equal is the Action's rule. */
class ReorderPagesRequest extends LockRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'pages' => ['required', 'array', 'min:1', 'max:1000'],
            'pages.*' => ['required', 'uuid'],
        ];
    }

    /** @return list<string> */
    public function pages(): array
    {
        return array_values(array_map('strval', (array) $this->validated('pages')));
    }
}
