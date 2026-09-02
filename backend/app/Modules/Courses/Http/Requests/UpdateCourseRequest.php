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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            /*
            | `sometimes` rather than `required`: a PATCH that changes only the
            | price must not be refused for omitting a field it is not touching.
            | It may not be sent EMPTY, though — clearing it would put a course
            | back into the state this rule exists to end.
            */
            'subject' => ['sometimes', 'uuid'],
            'description' => ['nullable', 'string'],
            'slug' => ['nullable', 'string', 'max:255'],
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_sequential' => ['nullable', 'boolean'],
            /*
            | The private session's length (023 · FR-016أ). Bounded because the
            | column is an `unsignedSmallInteger`: SQLite stores any integer in
            | one and MySQL in strict mode rejects it, so a value only the
            | production database refuses is a value no local test can see.
            */
            'private_session_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
        ];
    }
}
