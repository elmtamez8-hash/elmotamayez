<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Models\User;
use App\Modules\Courses\Models\Course;
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
            /*
            | ⚠️ `->group()`, OR THE SAFETY VALVE OPENS ON A ROOM NOBODY MAY
            | ENTER. An individual cohort is born `closed` with `capacity: 1`
            | and no membership row, so `members_count` is 0 — and nothing stops
            | a teacher setting its status to `open` from the panel, after which
            | it satisfies `joinable()`. This predicate is what decides whether
            | the curriculum gate stays shut (FR-028أ/ب), so counting one would
            | lock a paying student out of content on the strength of a private
            | 1:1 group they can never join.
            */
            ->group()
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
            /*
            | ⚠️ `->group()` AS WELL AS THE COURSE. The course in the question is
            | what stops a caller naming any cohort uuid on the platform; this is
            | what stops them naming a private 1:1 cohort. Those are born `closed`
            | with `capacity: 1`, but a teacher can open one from the panel — and
            | this method is now reached from a STUDENT-supplied uuid on the
            | subscription path (027), which makes an opened individual cohort a
            | way into another named student's room and its thread.
            */
            ->group()
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{id: int, course_id: int, workspace_id: int, name: string, course_uuid: string, is_joinable: bool}|null */
    public function describeGroupCohort(string $uuid): ?array
    {
        $cohort = Cohort::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $uuid)
            // See the contract: no course narrows this, so the group filter is
            // the only thing standing between a stranger's uuid and a private
            // 1:1 room. The caller proves the rest from what comes back.
            ->group()
            ->first(['id', 'course_id', 'workspace_id', 'name', 'status', 'capacity', 'members_count']);

        if ($cohort === null) {
            return null;
        }

        /*
        | ⚠️ THE COURSE UUID IS READ WITH ITS OWN BYPASS, NOT THROUGH `->with()`.
        | An eager load runs a SECOND query on which `Course`'s own
        | `BelongsToWorkspace` scope applies afresh — the bypass above frees only
        | the outer read. This method is asked about ANOTHER teacher's group by
        | design (that is the case it exists to refuse), and from the panel the
        | reader's own workspace is a real number: the relation came back null and
        | the refusal became a 500. That is the fifth layer of the 024 defect,
        | reached from a new door, and a test with one workspace cannot see it.
        */
        $course = Course::query()
            ->withoutWorkspaceScope()
            ->whereKey($cohort->course_id)
            ->first(['uuid', 'status']);

        if ($course === null) {
            return null;
        }

        return [
            'id' => (int) $cohort->getKey(),
            'course_id' => (int) $cohort->course_id,
            'workspace_id' => (int) $cohort->workspace_id,
            'name' => (string) $cohort->name,
            'course_uuid' => (string) $course->uuid,
            /*
            | ⚠️ THE COURSE'S STATUS TRAVELS TOO (027 · FR-026). A subscription
            | opens access by enrolling in every PUBLISHED course the plan covers,
            | so a group whose course was unpublished between the order and the
            | approval produces no enrolment — and the membership step then
            | refuses with «لست مسجّلاً», after the money has committed and with
            | nothing on the officer's screen. Answering it here lets the approval
            | be refused while it can still be refused.
            */
            'course_status' => (string) $course->status,
            'is_joinable' => $cohort->isJoinable(),
        ];
    }

    public function isJoinable(int $cohortId): bool
    {
        $cohort = Cohort::query()
            ->withoutWorkspaceScope()
            ->whereKey($cohortId)
            ->first(['id', 'status', 'capacity', 'members_count']);

        /*
        | ⚠️ DELEGATED TO THE MODEL, NEVER RE-SPELLED. `Cohort::isJoinable()` is
        | `status === OPEN && ! isFull()`, and it is what `CohortResource` and the
        | picker already read. A third spelling of one question is how the card
        | says yes and the door says no — which is the defect FR-002 exists over.
        */
        return $cohort !== null && $cohort->isJoinable();
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
                /*
                | ⚠️ THE SERVER'S ANSWER, NOT A CONDITION THE BROWSER REBUILDS
                | (FR-002). Derived here from columns already selected, never by
                | asking `isJoinable(int)` once per row — this method feeds a
                | Resource, and a Resource runs once per row.
                */
                'is_joinable' => $cohort->isJoinable(),
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
