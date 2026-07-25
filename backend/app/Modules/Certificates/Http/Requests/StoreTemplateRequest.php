<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Http\Requests;

use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::CERTIFICATES_REGENERATE) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'html_template' => ['required', 'string'],
            'defaults' => ['nullable', 'array'],
        ];
    }
}
