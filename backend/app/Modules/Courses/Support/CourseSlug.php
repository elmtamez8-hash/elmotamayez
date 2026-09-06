<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Courses\Models\Course;
use Illuminate\Support\Str;

/**
 * The public URL segment for a course: `/courses/alryadyat-llthanwyt-alamt`.
 *
 * ⚠️ **A SLUG IS A URL, SO IT IS GENERATED ONCE AND NEVER RE-DERIVED.** Renaming
 * a course must not move its address: every link anybody shared would 404, and
 * the rename that broke them says nothing about it. `CreateCourse` asks for one;
 * nothing on the edit path recomputes it, and a teacher who wants a different
 * address types it — `slug` is fillable and validated unique.
 *
 * ⚠️ **AND IT CARRIES NO RANDOM SUFFIX.** `CreateCourse` used to write
 * `Str::slug($title.'-'.Str::random(6))`, which is unique with no lookup and
 * produces `alryadyat-...-w2uxnk` — a readable slug with a licence plate glued
 * to it, which is most of the reason to have a slug at all thrown away. The
 * numbered fallback below costs one indexed `exists` on a create.
 *
 * The generated value reads as a consonant skeleton and that is a property of
 * the writing system, not a gap in the mapping: Arabic does not write short
 * vowels, so «الرياضيات» can only ever come back as `alryadyat`. It is a
 * DEFAULT, not an answer — {@see TeacherSlug}, which says the same thing about
 * names and for the same reason.
 */
final class CourseSlug
{
    /**
     * A slug for `$title` that no other course on the platform holds.
     *
     * `$exceptId` is the course being saved: without it, re-saving a course
     * would find its OWN slug taken and hand it `-2` — a new URL on every save,
     * and the old one dead.
     */
    public static function for(string $title, ?int $exceptId = null): string
    {
        $base = Str::slug(self::toLatin($title));

        // A title written entirely in characters the map has nothing for, or an
        // empty one. A URL segment is required, so it degrades to something
        // valid rather than to `/courses/`.
        if ($base === '') {
            $base = 'course';
        }

        $slug = $base;
        $suffix = 1;

        while (self::taken($slug, $exceptId)) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    /**
     * ICU, not `Str::transliterate`.
     *
     * Measured on «الرياضيات للثانوية العامة — الفصل الأول» (2026-09-06):
     * ICU gives `alryadyat-llthanwyt-alamt-alfsl-alawl`, `Str::slug` alone gives
     * `alryadyat-llthanoy-alaaam-alfsl-alaol`, and `Str::transliterate` gives
     * `lrydyt-llthnwy-at-lm-at-lfsl-lawl` — it drops the alif of «ال» and
     * expands ة to "at", which is the least readable of the three.
     *
     * intl ships with PHP but is not guaranteed enabled, so the fallback stays.
     * It produces a different slug for the same title, which matters only for a
     * course created on a host without intl — a cosmetic difference where a
     * fatal error would be an outage.
     */
    private static function toLatin(string $title): string
    {
        if (! class_exists(\Transliterator::class)) {
            return Str::transliterate($title);
        }

        $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');

        if ($transliterator === null) {
            return Str::transliterate($title);
        }

        $latin = $transliterator->transliterate($title);

        return $latin === false ? Str::transliterate($title) : $latin;
    }

    /**
     * ⚠️ withoutWorkspaceScope: the slug is a PLATFORM-wide URL.
     *
     * Two teachers still share one `/courses/{slug}` namespace, and a scoped
     * check would let the second one through — where the unique index then
     * rejects the insert, in production, while a teacher is creating a course.
     */
    private static function taken(string $slug, ?int $exceptId): bool
    {
        return Course::query()
            ->withoutWorkspaceScope()
            ->where('slug', $slug)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }
}
