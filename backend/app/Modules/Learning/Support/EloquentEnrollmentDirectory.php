<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Contracts\EnrollmentDirectory;
use Carbon\CarbonImmutable;

/**
 * Learning's answer to "is this person entitled?".
 *
 * Queries deliberately run without the workspace scope: a student is enrolled
 * with many teachers and asks about a lesson before any workspace is current, so
 * scoping here would return nothing and silently deny every playback. The guard
 * is the student's own id, which is stricter than a workspace filter would be.
 */
class EloquentEnrollmentDirectory implements EnrollmentDirectory
{
    public function hasActiveEnrollment(User $user, int $courseId): bool
    {
        return Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->exists();
    }

    public function hasActiveEnrollmentInWorkspace(User $user, int $workspaceId): bool
    {
        return Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->exists();
    }

    /** @return list<int> */
    public function activeCourseIdsFor(User $user): array
    {
        $ids = Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('status', 'active')
            ->pluck('course_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        return array_values($ids);
    }

    /** @return list<int> */
    public function enrollmentIdsFor(User $user): array
    {
        // No status filter — see the interface. A finished or cancelled enrolment
        // is still a row about this person, and an export that dropped it would be
        // an incomplete answer to a legal request.
        $ids = Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }

    /**
     * ⚠️ ACTIVE ENROLMENTS ONLY, AND ONE QUERY THAT ANSWERS BOTH HALVES. A
     * cancelled enrolment is not a right anybody still holds, so keeping a
     * recording for it would be keeping it for nobody — and asking twice, once for
     * the maximum and once for "does a null exist", is two scans of the largest
     * table in the workspace for one answer.
     *
     * `SUM(CASE …)` rather than a second `whereNull` query, and `MAX()` rather
     * than an ordered `first()`: both engines answer this from the same scan.
     *
     * @return array{0: CarbonImmutable|null, 1: bool}
     */
    public function accessHorizonFor(int $workspaceId): array
    {
        $row = Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->selectRaw('MAX(expires_at) as latest_expiry')
            ->selectRaw('SUM(CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END) as open_ended')
            ->first();

        $latest = $row?->getAttribute('latest_expiry');

        return [
            $latest === null ? null : CarbonImmutable::parse((string) $latest),
            ((int) ($row?->getAttribute('open_ended') ?? 0)) > 0,
        ];
    }

    /** @return list<array{student_user_id: int, workspace_id: int}> */
    public function enrolledPairsInPeriod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /*
        | Overlap, not containment: an enrolment that began before the term and
        | runs past it covers the term completely, and one written the same way
        | as "starts inside" would miss every continuing student — which is most
        | of them.
        |
        | The upper bound is the start of the day after the period ends, because
        | `enrolled_at` is a timestamp and the bounds are dates.
        */
        $rows = Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('enrolled_at', '<', $to->startOfDay()->addDay())
            ->where(function ($query) use ($from): void {
                // ⚠️ GROUPED. Left at the top level the `orWhereNull` would
                // discard the date bound and return every enrolment ever made.
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', $from->startOfDay());
            })
            ->distinct()
            ->get(['student_user_id', 'workspace_id']);

        /** @var list<array{student_user_id: int, workspace_id: int}> $pairs */
        $pairs = $rows
            ->map(fn (Enrollment $row): array => [
                'student_user_id' => (int) $row->student_user_id,
                'workspace_id' => (int) $row->workspace_id,
            ])
            ->unique(fn (array $pair): string => $pair['student_user_id'].':'.$pair['workspace_id'])
            ->values()
            ->all();

        return $pairs;
    }

    /** @return list<int> */
    public function activeStudentIdsFor(int $workspaceId, ?int $courseId = null): array
    {
        $query = Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active');

        // ⚠️ THE WORKSPACE BOUND STAYS WHEN A COURSE IS NAMED. A course id
        // arrives from a request, and narrowing to it ALONE would answer about
        // another teacher's course for anyone who guessed one — the announcement
        // then lands on students the publisher has never taught, which is
        // precisely what FR-043 forbids. Belt and braces, deliberately: the
        // Action resolves the uuid inside the workspace too.
        if ($courseId !== null) {
            $query->where('course_id', $courseId);
        }

        /** @var list<int> $ids */
        $ids = $query
            ->distinct()
            ->orderBy('student_user_id')
            ->pluck('student_user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $ids;
    }
}
