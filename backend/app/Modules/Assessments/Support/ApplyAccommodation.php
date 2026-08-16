<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Models\Accommodation;

/**
 * The standing arrangement, applied — automatically, everywhere (FR-054).
 *
 * ⚠️ THE EXAM HALF IS ADVISORY, AND SAYING SO IS PART OF SHIPPING IT.
 * `exams.duration_minutes` is stored and displayed and enforced by nothing —
 * there is no server-side timer on an attempt today, in this spec or in 003 —
 * so extra time raises the number the student is shown and nothing more. Building
 * enforcement here to give the percentage something to bite would be a different
 * feature wearing this one's name. What the percentage DOES do is get
 * snapshotted onto the attempt when it is issued, on the precedent of
 * `attempt_items.points`: a revoked accommodation must not retroactively re-time
 * a paper already sat.
 *
 * The deadline half is real: {@see AssignmentDeadline} moves the date every
 * reader compares against, so a student with three extra days is never marked
 * late inside them, and the nightly sweep does not mark them missed either.
 */
class ApplyAccommodation
{
    /** @var array<int, Accommodation|null> memoised per request, keyed by student */
    private array $cache = [];

    public function forStudent(int $workspaceId, int $studentId): ?Accommodation
    {
        // ⚠️ Memoised because it is asked on every submission, every sweep row
        // and every attempt start. Without it the sweep asks once per student
        // per assignment, which is the N+1 the sweep exists to avoid being.
        return $this->cache[$studentId] ??= Accommodation::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('student_user_id', $studentId)
            ->active()
            ->first();
    }

    /** Extra days on a homework deadline, or zero. */
    public function extendedDays(int $workspaceId, int $studentId): int
    {
        $accommodation = $this->forStudent($workspaceId, $studentId);

        return $accommodation === null ? 0 : (int) $accommodation->extended_days;
    }

    /**
     * The exam duration this student is granted, rounded up to a whole minute.
     *
     * Null in, null out: an exam with no stated duration has nothing to extend,
     * and inventing one here would put a timer on a paper that never had one.
     */
    public function effectiveDuration(int $workspaceId, int $studentId, ?int $durationMinutes): ?int
    {
        if ($durationMinutes === null) {
            return null;
        }

        $accommodation = $this->forStudent($workspaceId, $studentId);
        $pct = $accommodation === null ? 0 : (int) $accommodation->extra_time_pct;

        return $pct === 0
            ? $durationMinutes
            : (int) ceil($durationMinutes * (100 + $pct) / 100);
    }

    /** Forget what was memoised — for a caller that has just granted or revoked one. */
    public function forget(int $studentId): void
    {
        unset($this->cache[$studentId]);
    }
}
