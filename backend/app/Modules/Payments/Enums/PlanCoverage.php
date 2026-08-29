<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * How wide a plan reaches (data-model §٧ · FR-026).
 *
 * Two cases and no third. «This teacher» and «this course» are the two questions
 * a student actually asks; a per-subject or per-cohort scope would be a third
 * predicate to keep in step with the charge branch and the eligibility read, and
 * nothing in US4 asks for one.
 */
enum PlanCoverage: string
{
    /** Everything this teacher publishes, for the duration. */
    case Workspace = 'workspace';

    /** One course, named by `coverage_uuid`. */
    case Course = 'course';

    public function label(): string
    {
        return match ($this) {
            self::Workspace => 'كلّ كورسات المدرّس',
            self::Course => 'كورس واحد',
        };
    }

    public function needsCourse(): bool
    {
        return $this === self::Course;
    }
}
