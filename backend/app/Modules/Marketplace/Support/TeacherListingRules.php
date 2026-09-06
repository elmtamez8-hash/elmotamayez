<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use Illuminate\Validation\Rule;

/**
 * ما يصفُ به المدرّسُ نفسَه، وقاعدتُه واحدةٌ مهما اختلفَ البابُ.
 *
 * ⚠️ بابانِ يكتبانِ الحقولَ نفسَها: الخطوةُ الثانيةُ من معالجِ الانضمام، وشاشةُ
 * «ملفّي» بعدَ الاعتماد. ونسختانِ من القاعدةِ الواحدةِ تفترقانِ عندَ أوّلِ تعديل،
 * ثمّ يقبلُ أحدُ البابَينِ ما يرفضُه الآخرُ بلا أن يفشلَ شيء — وهو العطبُ الذي دفعَ
 * ثمنَه `SchoolYear::scopeActivelyOffered()` من قبلُ حينَ صدَّقتِ الشاشةُ قائمةً
 * وصدَّقَ البابُ أخرى.
 *
 * ⚠️ و`Rule::in` لا `string|max:100`: بابٌ مفتوحٌ هنا يكتبُ نصّاً حرّاً في ملفِّ
 * مدرّسٍ لا يطابقُ أيَّ مرشِّحٍ يستطيعُ السوقُ عرضَه — مدرّسٌ مُدرَجٌ تحتَ مادّةٍ
 * لا يبحثُ عنها أحد، بلا أن يفشلَ شيء.
 */
final class TeacherListingRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function fields(): array
    {
        return [
            // Slugs, not ids — the applicant has not been placed in a workspace
            // yet, and since spec 009 the taxonomy is platform-wide anyway.
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*' => ['string', Rule::in(self::activeSlugs(Subject::class))],
            'grade_levels' => ['required', 'array', 'min:1'],
            'grade_levels.*' => ['string', Rule::in(self::activeSlugs(GradeLevel::class))],
            'years_experience' => ['required', 'integer', 'min:0', 'max:60'],
            'qualifications' => ['array'],
            'qualifications.*' => ['string', 'max:255'],
            /*
            | Spec 022 · FR-016 — a CLOSED SET, and not a catalogue.
            |
            | `teaching_languages` is published on the public teacher card
            | (`PublicFieldAllowlist::TEACHER_CARD`) and it was `string|max:5`, so
            | any five characters reached a profile and were rendered to visitors
            | — while the screen offered exactly three options.
            |
            | It stays a constant rather than a fifth runtime catalogue because
            | nothing about it is operational: the list is the languages the
            | PRODUCT is translated for, it changes when a translation ships, and
            | an operator screen for three rows is a table nobody edits and a
            | seeder nobody remembers to backfill.
            */
            'teaching_languages' => ['required', 'array', 'min:1'],
            'teaching_languages.*' => ['string', Rule::in(TeachingLanguages::all())],
            'headline' => ['required', 'string', 'max:150'],
            'bio' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'required' => 'هذا الحقل مطلوب.',
            'subjects.min' => 'اختر مادة واحدة على الأقل.',
            'subjects.*.in' => 'إحدى المواد المختارة غير متاحة.',
            'grade_levels.min' => 'اختر مرحلة دراسية واحدة على الأقل.',
            'grade_levels.*.in' => 'إحدى المراحل المختارة غير متاحة.',
            'teaching_languages.min' => 'اختر لغة تدريس واحدة على الأقل.',
            'teaching_languages.*.in' => 'إحدى لغات التدريس المختارة غير مدعومة.',
            'headline.max' => 'السطر التعريفي طويل جداً.',
            'bio.max' => 'الوصف طويل جداً.',
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
}
