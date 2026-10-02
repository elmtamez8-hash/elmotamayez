<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The tab asking: a uuid the browser makes once per open tab. */
class LockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['tab' => ['required', 'uuid']];
    }

    public function tab(): string
    {
        return (string) $this->validated('tab');
    }
}
