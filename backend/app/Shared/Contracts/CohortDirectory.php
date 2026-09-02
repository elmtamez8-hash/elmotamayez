<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;

/**
 * "Which group is this student in, and is there one they could join?"
 *
 * Owned and implemented by `Learning`; asked by `LiveSessions` (which sessions
 * may this student see), by `Community` (who reaches the group's thread) and by
 * the curriculum gate. Same shape as {@see EnrollmentDirectory}: a question that
 * needs an answer before the next line runs, not an event announcing something
 * happened.
 *
 * ⚠️ EVERY READ THAT IS ASKED ABOUT A LIST IS BULK BY SIGNATURE. A Resource runs
 * once per row, so a single-row read inside one is an N+1 by construction — the
 * `ClassSessionResource` defect arriving through a new door.
 *
 * ⚠️ {@see joinableCohortsExist()} IS THE MOST IMPORTANT SIGNATURE HERE. Without
 * it the mandatory-membership gate becomes a permanent lock on paid content the
 * day every group is full, closed or archived — a condition no action of the
 * student's can satisfy, which is the family of the worst defect this repository
 * records.
 *
 * ⚠️ AND THERE IS DELIBERATELY NO `cohortIdsForSessions()`. The design sketched
 * one, and `class_sessions.cohort_id` is LiveSessions' own column on LiveSessions'
 * own table: an implementation here would be Learning reading another module's
 * schema to hand back what that module already holds. What LiveSessions cannot
 * answer for itself is which cohorts the READER belongs to, and which courses
 * have any cohorts at all — those two are below, and both are bulk.
 */
interface CohortDirectory
{
    /**
     * Whether this student currently belongs to a group of this course.
     *
     * The content gate (FR-028أ) — asked on every curriculum read, so it is one
     * indexed existence query and nothing more.
     */
    public function hasOpenMembership(User $user, int $courseId): bool;

    /** The group they are in right now, or `null`. */
    public function openMembershipCohortId(User $user, int $courseId): ?int;

    /**
     * Whether ANY group of this course could be joined at this instant — open,
     * and not full.
     *
     * ⚠️ THE SAFETY VALVE (FR-028ب). A `false` here does not close a door; it
     * opens the whole curriculum, because the condition has become one no
     * student action can satisfy.
     */
    public function joinableCohortsExist(int $courseId): bool;

    /**
     * Whether this person was a member of this group AT ANY POINT.
     *
     * Reading the old group's thread survives the transfer (FR-046); writing to
     * it does not. Two different questions, which is why they are two methods
     * and not one row read twice.
     */
    public function wasEverMember(User $user, int $cohortId): bool;

    /**
     * Whether this person's membership of this group is open RIGHT NOW.
     *
     * ⚠️ THE SECOND OF THE PAIR THE DOCBLOCK ABOVE PROMISES, and it is asked of
     * the COHORT rather than of the course. `openMembershipCohortId()` answers
     * the same fact but needs a course id, and the thread's row does not carry
     * one — deriving it would be a join to fetch back something the cohort id
     * already settles.
     */
    public function isCurrentMember(User $user, int $cohortId): bool;

    /**
     * Everyone whose membership of this group is open — the roster and the
     * announcement fan-out.
     *
     * @return list<int>
     */
    public function activeMemberIdsFor(int $cohortId): array;

    /**
     * Every group this student currently belongs to, across every course.
     *
     * ⚠️ BULK BECAUSE THE CALLER IS A QUERY, NOT A ROW. The session discovery
     * list is built before any row is known, so it cannot ask course by course.
     *
     * @return list<int>
     */
    public function openMembershipCohortIdsFor(User $user): array;

    /**
     * Which of these courses have at least one group (of any status).
     *
     * ⚠️ "HAS GROUPS" IS NOT "HAS JOINABLE GROUPS", and the difference is the
     * whole of FR-036: a course with no group at all behaves exactly as it did
     * before this spec — its sessions are the course's, not a group's, and
     * nothing about it is gated. An archived group still means the course is one
     * that runs in groups.
     *
     * @param  list<int>  $courseIds
     * @return list<int>
     */
    public function coursesWithCohorts(array $courseIds): array;

    /**
     * The internal id of this group, IF it belongs to this course.
     *
     * ⚠️ THE COURSE IS PART OF THE QUESTION, NOT A COURTESY. Without it a
     * teacher assigning their own sessions could name any group uuid on the
     * platform and file their timetable under somebody else's run. Answering
     * `null` for a uuid that exists elsewhere is the same answer as for one that
     * does not exist at all — a distinct reply would be an oracle for which
     * uuids are real.
     */
    public function resolveCohortId(string $uuid, int $courseId): ?int;

    /*
    | Spec 023 · FR-010 — the groups of one course, as the PUBLIC may read them.
    |
    | ⚠️ FILTERED ON `individual_for_user_id IS NULL`, NEVER ON THE STATUS.
    | A private group is created `closed`, so filtering by status hides it today
    | for a reason that has nothing to do with whose it is — and the first day
    | one is opened for any reason at all, its owner's name is on the
    | marketplace. Ownership is the predicate; the status is a separate fact that
    | is also published.
    |
    | ⚠️ AND IT ANSWERS `seats_left`, NEVER `members_count` (FR-014). The
    | subtraction happens here so the two halves of it never both reach a
    | browser; `null` means no ceiling was declared, which is not a number and is
    | not zero.
    */
    /**
     * @return list<array{uuid: string, name: string, description: string|null, status: string, seats_left: int|null, id: int}>
     */
    public function publicCohortsFor(int $courseId): array;

    /**
     * The student's own one-seat group in this course, created on first use
     * (023 · FR-019ج · FR-019د).
     *
     * ⚠️ IDEMPOTENT BY UNIQUE INDEX, never by a read followed by a write. Two
     * acceptances for one student arriving together both read «no group» and
     * both create one; `unique(course_id, individual_for_user_id)` is what makes
     * the loser lose, and the loser is answered with the winner's id rather than
     * an error — a second group is not something the caller can do anything
     * about.
     *
     * Here rather than in the caller because `Cohort` is Learning's model and
     * LiveSessions may not reach for it — `CohortSessionVisibility` says so in as
     * many words, and one module borrowing another's model once is how the
     * boundary stops being one. Named in prose rather than with `{@see}`: an
     * `App\Shared` contract that imports a module is the coupling inverted.
     *
     * @return int the cohort's primary key
     */
    public function ensureIndividualCohort(int $courseId, int $workspaceId, User $student, ?User $creator): int;
}
