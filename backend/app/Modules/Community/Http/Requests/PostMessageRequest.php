<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Four thousand characters, which is a long paragraph and not a file.
            // The column is TEXT; the ceiling here is what stops one request
            // filling a page of fifty on its own.
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ];
    }
}
