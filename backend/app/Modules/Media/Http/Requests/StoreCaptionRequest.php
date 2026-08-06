<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Requests;

use App\Modules\Media\Enums\CaptionKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCaptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The extension is a hint, not the check: the file is parsed in the
            // Action, which is the only thing that proves it will actually play.
            'file' => ['required', 'file', 'max:2048'],
            'language' => ['sometimes', 'string', 'max:8'],
            'kind' => ['sometimes', Rule::enum(CaptionKind::class)],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
