<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Requests;

/** A PDF to import: its name and size, and the page it goes after (a page uuid of this board). */
class RequestBoardImportRequest extends LockRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'after' => ['nullable', 'uuid'],
        ];
    }
}
