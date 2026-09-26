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
            // `manual` — chosen in account settings, the source of truth.
            // `browser` — the sign-in stamp; never overwrites a manual choice.
            'source' => ['sometimes', 'string', 'in:manual,browser'],
            // The #239 client's spelling of `source: browser`, still accepted so
            // a tab loaded before this release keeps stamping instead of choosing.
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
