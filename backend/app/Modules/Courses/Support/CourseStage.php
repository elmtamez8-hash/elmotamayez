<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Support\TeacherListingRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * The broad stage a course is taught at — `courses.grade_level`.
 *
 * ⚠️ ONE SPELLING FOR TWO DOORS. `CreateCourseRequest` and `UpdateCourseRequest`
 * both validate this field, and a rule written out twice is two answers to one
 * question the first time somebody edits one of them.
 *
 * ⚠️ `is_active` ALONE, with no participation condition — the predicate
 * {@see TeacherListingRules} reads and
 * deliberately not the marketplace's. Narrowing it to stages that already have a
 * course would be a circular lock: the first course of a stage could never be
 * filed under it.
 *
 * NULLABLE on purpose. Making it required is a product decision nobody has
 * taken, and 95 of the 96 courses that exist carry no stage at all — refusing
 * every future PATCH to any of them would be a migration disguised as a
 * validation rule.
 */
final class CourseStage
{
    /** @return array<int, mixed> */
    public static function rules(): array
    {
        return ['nullable', 'string', self::in()];
    }

    private static function in(): In
    {
        /** @var list<string> $slugs */
        $slugs = GradeLevel::query()->where('is_active', true)->pluck('slug')->all();

        return Rule::in($slugs);
    }
}
