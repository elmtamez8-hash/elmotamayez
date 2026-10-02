<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Requests;

/** A picture to upload: its name and size. Its type is decided by the bytes, at `complete`. */
class RequestBoardFileRequest extends LockRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
        ];
    }
}
