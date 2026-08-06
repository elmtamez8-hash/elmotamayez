<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Settlement\Models\SettlementRate;
use DateTimeInterface;

/**
 * Which rate applied at a given moment.
 *
 * The ordering is declared here once (FR-014ب) rather than decided at each call
 * site: most specific first — subject *and* grade, then subject, then the
 * teacher's general rate — and within one level of specificity, the newest rate
 * that had already taken effect.
 *
 * "Had already taken effect" is measured against the SESSION, not against now.
 * Resolving against the clock would reprice work already done every time a rate
 * changed, which is the dispute this whole context exists to prevent (FR-011).
 */
class RateResolver
{
    public function resolve(
        int $teacherProfileId,
        ClassSessionType $type,
        DateTimeInterface $moment,
        ?int $subjectId = null,
        ?string $gradeLevel = null,
    ): ?SettlementRate {
        $candidates = SettlementRate::query()
            ->where('teacher_profile_id', $teacherProfileId)
            ->where('session_type', $type->value)
            ->where('effective_from', '<=', $moment)
            ->where(fn ($query) => $query->whereNull('subject_id')->orWhere('subject_id', $subjectId))
            ->where(fn ($query) => $query->whereNull('grade_level')->orWhere('grade_level', $gradeLevel))
            ->get();

        // Sorted in PHP rather than SQL: specificity is a derived rank, and an
        // ORDER BY expressing it would be a CASE statement nobody reads twice.
        // The candidate set is at most a handful of rows per teacher.
        //
        // Compared as a PAIR, not as a formatted string: PHP's spaceship walks
        // arrays element by element, while "3-999" sorts above "3-1000" because
        // '9' beats '1' — which would quietly hand the older rate the win.
        return $candidates
            ->sort(fn (SettlementRate $a, SettlementRate $b): int => [
                $b->specificity(),
                $b->effective_from->getTimestamp(),
            ] <=> [
                $a->specificity(),
                $a->effective_from->getTimestamp(),
            ])
            ->first();
    }
}
