<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::COURSES_CREATE) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            /*
            | ⚠️ REQUIRED, AND IT NEVER WAS. `courses.subject_id` arrived with
            | 007's pricing migration, was made fillable, and then no request, no
            | Action and no screen ever wrote it — so it was NULL on every course
            | ever created (77 of 77 on the development database, measured
            | 2026-08-27). Everything downstream that groups by subject therefore
            | had nothing to group: the marketplace's own facet, and the homework
            | and practice filters added the same day.
            |
            | A uuid with no `exists` rule, the idiom every filter here follows:
            | Laravel's `exists` is a raw query, and `subjects` is platform-level
            | reference data resolved inside the Action instead.
            */
            'subject' => ['required', 'uuid'],
            'description' => ['nullable', 'string'],
            /*
            | ⚠️ UNIQUE ACROSS THE PLATFORM, NOT WITHIN THE WORKSPACE.
            | `/courses/{slug}` is one namespace read by guests, so the index behind
            | this rule carries no `workspace_id` — and without the rule a teacher
            | who types a slug another teacher already holds gets a raw integrity
            | violation instead of a sentence under the field.
            |
            | ⚠️ AND `Rule::unique` IS A RAW QUERY WITH NO GLOBAL SCOPE ON IT,
            | which is exactly what is wanted here and is why `WorkspaceRules` is NOT
            | used: the question is whether ANY course on the platform holds this
            | address.
            */
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('courses', 'slug')],
            // Integer minor units, never `numeric`: a decimal accepted here is
            // a hundredth of the price the teacher meant.
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_sequential' => ['nullable', 'boolean'],
        ];
    }
}
