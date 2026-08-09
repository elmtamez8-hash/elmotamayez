<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use DateTimeInterface;

/**
 * What the platform has approved to pay for one seat of this course's sessions.
 *
 * Exists so Payments can price a credit package without reaching into
 * Settlement's models, which Constitution III forbids. Settlement owns the rate
 * and binds the implementation; Payments depends on this interface and does not
 * know `settlement_rates` exists. Same shape as {@see EnrollmentDirectory}.
 *
 * Returns a NUMBER, never a model. A model would carry `approved_by`,
 * `rate_change_request_id` and the teacher's own pay with it, one `->toArray()`
 * away from a student-facing payload — and the whole point of keeping the two
 * money contexts apart (spec 014's ContextIsolationTest) would be undone by an
 * accessor nobody meant to expose.
 *
 * Takes the COURSE, not the teacher profile, so the derivation lives inside
 * Settlement — the side that already derives the same inputs from a session when
 * it accrues. One class deriving it in both directions, not two that agree until
 * they don't.
 *
 * ⚠️ `ClassSessionType` is the first module import in this layer; the three
 * existing contracts take only `User` and primitives. Accepted deliberately: the
 * alternative is a bare string, and a string parameter is a spelling mistake
 * that returns `null` and reads as "no approved rate" — a silently empty package
 * list instead of a type error.
 */
interface ApprovedRateDirectory
{
    /**
     * The approved rate in minor units, or null when none applies.
     *
     * "Applies" is measured against $moment, not against now: pricing a package
     * today against a rate approved tomorrow would quote a number the settlement
     * side will never pay.
     *
     * Null is a normal answer, not an error — a teacher with no approved rate
     * yet simply has no packages to sell.
     */
    public function approvedRateMinorForCourse(
        int $courseId,
        ClassSessionType $type,
        DateTimeInterface $moment,
    ): ?int;
}
