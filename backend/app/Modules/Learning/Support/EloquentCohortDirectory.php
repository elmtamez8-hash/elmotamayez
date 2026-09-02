<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Models\User;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Shared\Contracts\CohortDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Learning's answer to "which group?".
 *
 * ⚠️ EVERY QUERY HERE RUNS WITHOUT THE WORKSPACE SCOPE, and that is the same
 * decision {@see EloquentEnrollmentDirectory} wrote down: a student is a member
 * of no workspace, so `WorkspaceContext::id()` is null, `WorkspaceScope` adds no
 * condition anyway — and the moment a request DOES carry a context (a teacher
 * reading their own screen) a scoped query here would answer about the reader's
 * workspace rather than about the course being asked about. The guard is the
 * student's own id and the course's own id, both of which are stricter than a
 * workspace filter.
 */
class EloquentCohortDirectory implements CohortDirectory
{
    public function hasOpenMembership(User $user, int $courseId): bool
    {
        return CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('course_id', $courseId)
            ->whereNull('closed_at')
            ->exists();
    }

    public function openMembershipCohortId(User $user, int $courseId): ?int
    {
        $id = CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('course_id', $courseId)
            ->whereNull('closed_at')
            ->value('cohort_id');

        return $id === null ? null : (int) $id;
    }

    public function joinableCohortsExist(int $courseId): bool
    {
        return Cohort::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $courseId)
            ->joinable()
            ->exists();
    }

    public function wasEverMember(User $user, int $cohortId): bool
    {
        // No `closed_at` condition at all — "ever" is the question (FR-046), and
        // adding one here is how the departed member loses the thread they are
        // entitled to keep reading.
        return CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('cohort_id', $cohortId)
            ->exists();
    }

    public function isCurrentMember(User $user, int $cohortId): bool
    {
        return CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('cohort_id', $cohortId)
            ->whereNull('closed_at')
            ->exists();
    }

    /** @return list<int> */
    public function activeMemberIdsFor(int $cohortId): array
    {
        $ids = CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('cohort_id', $cohortId)
            ->whereNull('closed_at')
            ->pluck('student_user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }

    /** @return list<int> */
    public function openMembershipCohortIdsFor(User $user): array
    {
        $ids = CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->whereNull('closed_at')
            ->pluck('cohort_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }

    /**
     * @param  list<int>  $courseIds
     * @return list<int>
     */
    public function coursesWithCohorts(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $ids = Cohort::query()
            ->withoutWorkspaceScope()
            ->whereIn('course_id', $courseIds)
            ->distinct()
            ->pluck('course_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }

    public function resolveCohortId(string $uuid, int $courseId): ?int
    {
        $id = Cohort::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $uuid)
            ->where('course_id', $courseId)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    public function publicCohortsFor(int $courseId): array
    {
        $cohorts = Cohort::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $courseId)
            // Ownership, not status — see the contract. An archived group is
            // dropped by name because FR-010 says so; a `closed` one is
            // published WITH its status, because «مغلقة» is information a
            // visitor deciding between groups needs.
            ->group()
            ->where('status', '!=', Cohort::ARCHIVED)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'description', 'status', 'capacity', 'members_count']);

        $out = [];

        foreach ($cohorts as $cohort) {
            $out[] = [
                'id' => (int) $cohort->getKey(),
                'uuid' => (string) $cohort->uuid,
                'name' => (string) $cohort->name,
                'description' => $cohort->description,
                /*
                | The three states of FR-011, and CLOSED OUTRANKS FULL.
                | A closed group cannot be joined whatever its seat count, so
                | «ممتلئة» there would invite the visitor to wait for a place
                | that will never open. `isFull()` is derived from the counter
                | against the ceiling and never stored, and a group with no
                | declared ceiling is never full.
                */
                'status' => match (true) {
                    $cohort->status === Cohort::CLOSED => 'closed',
                    $cohort->isFull() => 'full',
                    default => 'open',
                },
                'seats_left' => $cohort->seatsLeft(),
            ];
        }

        return $out;
    }

    public function ensureIndividualCohort(int $courseId, int $workspaceId, User $student, ?User $creator): int
    {
        $existing = $this->findIndividualCohort($courseId, (int) $student->getKey());

        if ($existing !== null) {
            return $existing;
        }

        $cohort = new Cohort;

        /*
        | ⚠️ `forceFill`, BECAUSE `status` AND `members_count` ARE NOT `$fillable`.
        | A `create()` array carrying `status` has it discarded in silence and the
        | group is born `open` — which puts one student's private group into the
        | course's joinable list and into `joinableCohortsExist()`, where FR-028أ
        | would then gate the whole curriculum behind a group nobody else may
        | ever enter.
        |
        | ⚠️ AND NO MEMBERSHIP IS OPENED HERE OR ANYWHERE. `cohort_memberships`
        | carries `unique(student_user_id, course_id, closed_slot)` — ONE open
        | membership per (student, course) — so opening one would CLOSE the
        | student's Saturday group and take away the weekly class they paid for,
        | in exchange for one private hour. The private session reaches its
        | student through their own booking, which is where a lesson they hold a
        | seat in belongs.
        */
        $cohort->forceFill([
            'workspace_id' => $workspaceId,
            'course_id' => $courseId,
            // `unique(course_id, name)` is on the NAME, so the id is part of it:
            // two students called Sara in one course would otherwise make the
            // second acceptance fail with a message about a group name.
            'name' => mb_substr('حصص خاصة — '.$student->name, 0, 100).' #'.$student->getKey(),
            'capacity' => 1,
            'status' => Cohort::CLOSED,
            'individual_for_user_id' => $student->getKey(),
            'created_by' => $creator?->getKey(),
        ]);

        try {
            $cohort->save();
        } catch (UniqueConstraintViolationException) {
            // The other acceptance won the index. Hand back its group rather
            // than an error: one group is exactly the outcome asked for.
            $winner = $this->findIndividualCohort($courseId, (int) $student->getKey());

            if ($winner === null) {
                throw new RuntimeException('تعذّر تجهيز مجموعة الحصص الخاصة.');
            }

            return $winner;
        }

        return (int) $cohort->getKey();
    }

    private function findIndividualCohort(int $courseId, int $studentUserId): ?int
    {
        $id = Cohort::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $courseId)
            ->where('individual_for_user_id', $studentUserId)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
