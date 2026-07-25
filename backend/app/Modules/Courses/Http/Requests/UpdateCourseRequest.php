<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::COURSES_UPDATE) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'slug' => ['nullable', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_sequential' => ['nullable', 'boolean'],
        ];
    }
}
