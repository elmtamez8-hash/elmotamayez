<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTimezoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // An IANA name the server itself recognises — the one the browser
            // reports (`Intl.DateTimeFormat().resolvedOptions().timeZone`).
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            // The browser's own stamp on sign-in passes true: it fills an empty
            // column and never overwrites a zone somebody chose.
            'only_if_unset' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'timezone.required' => 'اختر المنطقة الزمنية.',
            'timezone.timezone' => 'المنطقة الزمنية غير معروفة.',
        ];
    }
}
