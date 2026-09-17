<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * How wide a plan reaches (data-model §٧ · FR-026 · ٠٣٦ FR-021).
 *
 * «This teacher» and «this course» are the two questions a student asks on a
 * course page. ٠٣٦ adds a third — «this group» — because a cohort is what a
 * teacher actually sells a term of, and FR-014 hides a cohort no plan reaches.
 *
 * ⚠️ A THIRD COVERAGE, NOT A THIRD COLUMN. `coverage_uuid` already names whatever
 * the coverage points at; a `cohort_uuid` beside it would be a second answer to
 * one question, null on two rows out of three, and the first reader to forget it
 * opens a group plan onto every course the teacher has.
 */
enum PlanCoverage: string
{
    /** Everything this teacher publishes, for the duration. */
    case Workspace = 'workspace';

    /** One course, named by `coverage_uuid`. */
    case Course = 'course';

    /** One group, named by `coverage_uuid`; it opens that group's course alone. */
    case Cohort = 'cohort';

    public function label(): string
    {
        return match ($this) {
            self::Workspace => 'كلّ كورسات المدرّس',
            self::Course => 'كورس واحد',
            self::Cohort => 'مجموعة واحدة',
        };
    }

    /**
     * Whether `coverage_uuid` must be filled.
     *
     * ⛔ REPLACES `needsCourse()`, and the rename is the point rather than tidying.
     * That name answered «is this the Course case» while every caller was really
     * asking «does this row need a uuid» — two questions that agreed while there
     * were two cases and disagree the moment there are three. Read literally it
     * sends a cohort plan down the «no uuid needed» branch, which is exactly what
     * `SavePlan` did: it NULLED the uuid and saved a group plan pointing at no
     * group.
     *
     * ⚠️ AND THE `match` CARRIES NO `default`, MATCHING ON THE CASE RATHER THAN ON
     * `->value`. That is what makes the analyser name every unhandled case the day
     * a fourth is added; a `default` arm, or a comparison against the string, is
     * silent and the new coverage quietly behaves like a workspace one.
     *
     * ⚠️ WHICH TABLE the uuid names is deliberately NOT answered here — it is
     * `CoveredCourses`, in one place, because the answer is a query and an enum
     * that performs queries is an enum every caller has to mock.
     */
    public function requiresUuid(): bool
    {
        return match ($this) {
            self::Workspace => false,
            self::Course, self::Cohort => true,
        };
    }
}
