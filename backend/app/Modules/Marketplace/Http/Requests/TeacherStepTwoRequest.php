<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Support\TeachingLanguages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeacherStepTwoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Slugs, not ids — the applicant has not been placed in a workspace
            // yet, and since spec 009 the taxonomy is platform-wide anyway.
            //
            // ⚠️ `Rule::in`, NOT `string|max:100`. An open door here writes free
            // text into a teacher's profile that then matches no filter the
            // marketplace can ever offer — a teacher listed under a subject
            // nobody can search for, with nothing failing. The list is the same
            // one `/api/v1/signup/subjects` renders, so the door and the screen
            // give one answer (SC-003).
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*' => ['string', Rule::in(self::activeSlugs(Subject::class))],
            'grade_levels' => ['required', 'array', 'min:1'],
            'grade_levels.*' => ['string', Rule::in(self::activeSlugs(GradeLevel::class))],
            'years_experience' => ['required', 'integer', 'min:0', 'max:60'],
            'qualifications' => ['array'],
            'qualifications.*' => ['string', 'max:255'],
            /*
            | Spec 022 · FR-016 / T076 — a CLOSED SET, and not a catalogue.
            |
            | `teaching_languages` is published on the public teacher card
            | (`PublicFieldAllowlist::TEACHER_CARD`) and it was `string|max:5`,
            | so any five characters reached a profile and were rendered to
            | visitors — while the screen offered exactly three options. The
            | same two-answers shape this spec exists to close, from the other
            | side of the same form.
            |
            | It stays a constant rather than becoming a fifth runtime catalogue
            | because nothing about it is operational: the list is the languages
            | the PRODUCT is translated for, it changes when a translation
            | ships, and an operator screen for three rows is a table nobody
            | edits and a seeder nobody remembers to backfill. The audit in
            | `docs/README.md` records that decision beside the four that went
            | the other way.
            */
            'teaching_languages' => ['required', 'array', 'min:1'],
            'teaching_languages.*' => ['string', Rule::in(TeachingLanguages::all())],
            'headline' => ['required', 'string', 'max:150'],
            'bio' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * ⚠️ `is_active` ALONE, with NO participation condition — the same predicate
     * `ListSignupTaxonomy` reads and deliberately not the marketplace's.
     * Narrowing this to entries with a listed teacher would be the circular lock
     * moved from the screen to the door: the first teacher on the platform would
     * be refused every subject there is.
     *
     * @param  class-string<Subject|GradeLevel>  $model
     * @return list<string>
     */
    private static function activeSlugs(string $model): array
    {
        /** @var list<string> */
        return $model::query()->where('is_active', true)->pluck('slug')->all();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'هذا الحقل مطلوب.',
            'subjects.min' => 'اختر مادة واحدة على الأقل.',
            'subjects.*.in' => 'إحدى المواد المختارة غير متاحة.',
            'grade_levels.min' => 'اختر مرحلة دراسية واحدة على الأقل.',
            'grade_levels.*.in' => 'إحدى المراحل المختارة غير متاحة.',
            'teaching_languages.min' => 'اختر لغة تدريس واحدة على الأقل.',
            'teaching_languages.*.in' => 'إحدى لغات التدريس المختارة غير متاحة.',
        ];
    }
}
