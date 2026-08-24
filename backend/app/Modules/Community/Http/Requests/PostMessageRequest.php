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
            /*
            | ⚠️ `required_without` AND NOT `required` — a voice note has no words
            | (`FR-060`). Left as `required`, every attachment-only message is a
            | 422 about a field the sender was never shown, which is exactly what
            | the first end-to-end run answered.
            |
            | Four thousand characters, which is a long paragraph and not a file.
            | The column is TEXT; the ceiling here is what stops one request
            | filling a page of fifty on its own.
            */
            'body' => ['required_without:attachment', 'nullable', 'string', 'max:4000'],

            /*
            | The uuid of an asset already uploaded through
            | `POST /conversations/{conversation}/attachments`. Whether it belongs
            | to THIS conversation, is `Ready`, and is not already on another
            | message is decided in `PostMessage` — three questions a validation
            | rule cannot ask and a `exists:` rule would answer wrongly, since an
            | asset uuid from any other thread exists perfectly well.
            */
            'attachment' => ['nullable', 'string', 'uuid'],
        ];
    }
}
