<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Courses\Actions\CreateCourse;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Support\CourseStage;
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
            | ⚠️ THE FIRST WRITER `courses.grade_level` HAS EVER HAD. Fillable
            | since 006, named by the settlement-rate key and by the course
            | leaderboard, and assigned by nothing — NULL on 95 of 96 rows.
            | See {@see CourseStage} for why it is nullable rather than required.
            */
            'grade_level' => CourseStage::rules(),
            /*
            | ⚠️ **REQUIRED, AND `courses.course_type` HAD NEVER HAD A WRITER AT
            | ALL** — `subject_id`'s history one column along, except this one
            | carries a DB DEFAULT, so instead of a visible NULL it produced a
            | confident wrong answer: `recorded` on 6 of the 7 courses on
            | production (measured 2026-09-15), including one with eight live
            | sessions and an open group. The student's course page drops its
            | «الحصص» tab on `recorded`, and the public page badges it «مسجّل».
            |
            | Required rather than nullable — the opposite of `grade_level` one
            | line up, and deliberately: a stage nobody chose reads as «unset»,
            | while a TYPE nobody chose reads as a declaration the teacher never
            | made. There is no honest default for it.
            |
            | ⚠️ AND THE RULE HERE GUARDS THE HTTP DOOR ALONE. Every seeder
            | writes inside `Model::unguarded()`, and a Filament create page —
            | this resource has only Edit and List today — would build the row
            | with `new Model($data)` and never reach a FormRequest at all. So
            | the refusal that covers all of them is {@see CreateCourse::handle()}'s,
            | which is where this repository puts a business rule anyway.
            */
            'course_type' => ['required', 'string', Rule::in(Course::types())],
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
