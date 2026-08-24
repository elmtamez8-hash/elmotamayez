<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Tenancy\Support\Permissions;

/**
 * Who may say how a teacher's grade components combine (FR-049).
 *
 * ⚠️ `REVIEWS_PERIODIC_MANAGE` REUSED ON PURPOSE, AND NOT `GRADING_PERFORM`.
 * The two look interchangeable and are not: `grading.perform` means "may mark
 * this student's work", which an assistant legitimately holds — and an assistant
 * who marks essays has no business rewriting the weighting of the whole course.
 * The weights decide the single number on the document that reaches the
 * guardian, which is the same authority over the same people that the periodic
 * assessment is, held by the same person.
 *
 * Reusing it also means no fifth backfill migration for existing roles: a new
 * constant reaches nobody who already exists, because `SeedDefaultRoles` runs
 * once at workspace creation — a mistake this repository has now made four
 * times, each one green in the suite because every fixture creates its workspace
 * after the seed.
 */
class GradingSchemePolicy
{
    public function manage(User $user): bool
    {
        return $user->can(Permissions::REVIEWS_PERIODIC_MANAGE);
    }
}
