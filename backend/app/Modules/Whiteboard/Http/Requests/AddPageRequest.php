<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Requests;

/** A new page: where (`after`), and blank or a copy (`duplicate_of`). Both are found through the board. */
class AddPageRequest extends LockRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'after' => ['nullable', 'uuid'],
            'duplicate_of' => ['nullable', 'uuid'],
        ];
    }
}
