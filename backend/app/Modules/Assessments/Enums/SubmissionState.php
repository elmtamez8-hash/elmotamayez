<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

/**
 * Where a submission stands against its deadline (FR-045).
 *
 * ⚠️ This is the one stored-rather-than-derived value in spec 008, and the
 * reason is the third case. "Late" compares a submission time against a deadline
 * that is EXTENSIBLE for a named student, and FR-051 requires non-submitters to
 * be marked without anyone touching them — so the state changes with the passage
 * of time rather than by anybody's action, which is exactly what a read-time
 * derivation cannot announce. The nightly sweep writes `Missed`; submitting
 * writes `OnTime` or `Late` at the moment it happens.
 */
enum SubmissionState: string
{
    case OnTime = 'on_time';
    case Late = 'late';
    case Missed = 'missed';
}
