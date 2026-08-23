<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;

/**
 * Whether a member of a teacher's team may act on a given thing right now.
 *
 * Here rather than inside `Community` so `Assessments`, `Courses` and
 * `LiveSessions` can ask without reaching into another module's models, which
 * Constitution III forbids. Same shape as {@see EnrollmentDirectory}: Community
 * owns the row and binds the implementation, everyone else depends on this
 * interface alone.
 *
 * A query, not an event. Events report that something happened; this asks a
 * question that needs an answer before the next line runs.
 *
 * ⚠️ IT ANSWERS "WHERE", NEVER "WHAT". What an assistant may do is a spatie
 * permission granted from the roles screen (`NFR-004`); this interface only
 * narrows the ground they may do it on. Every caller therefore asks BOTH — the
 * existing `$this->authorize()` and then this — and an implementation that
 * started answering the second question would be the two-permission-systems
 * defect the design rejected in ق-١.
 *
 * ⚠️ AND IT RETURNS `true` FOR PEOPLE WHO ARE NOT ASSISTANTS. A teacher, an
 * owner, a super admin and a stranger all pass every method here, because none
 * of them is confined by an assignment — the confinement is what this asks
 * about. Reading a `true` as "this person may act" is the misuse to guard
 * against: it means "nothing in the assistant scope stops them", and the
 * permission check beside it is what says whether they may.
 */
interface AssistantScopeDirectory
{
    /**
     * Whether this user holds a LIVE assistant assignment in this workspace.
     *
     * ⚠️ THE KEY THE FINANCIAL WALL IS BUILT ON (`FR-003`), so it is asked on
     * every permission check and must be memoised per request. A `Gate::before`
     * hook runs dozens of times on one Filament page.
     *
     * Live means `revoked_at IS NULL`, which is what makes a withdrawal take
     * effect on the very next request with nothing to expire — `SC-003`.
     */
    public function isAssistantIn(User $user, int $workspaceId): bool;

    /**
     * Whether this user may act on this course in this workspace.
     *
     * True for anyone who is not an assistant here, and for an assistant whose
     * scope is empty — no rows means EVERY course, never none.
     *
     * ⚠️ `$courseId` IS NULLABLE, AND THE NULL BRANCH IS THE ONE WORTH READING.
     * Not everything an assistant touches hangs off a course: an exam can be set
     * for the workspace at large, and a paper sat against one has no course to
     * compare a confinement with. A CONFINED assistant is refused there — the
     * alternative is a hole shaped exactly like the confinement, reachable by
     * setting the exam without a course. It lives here rather than in each of the
     * three policies that ask, because one rule in three places is how the third
     * one gets it wrong.
     */
    public function mayActOnCourse(User $user, int $workspaceId, ?int $courseId): bool;

    /**
     * Whether this user may act on this student in this workspace.
     *
     * ⚠️ THE INTERSECTION WITH ENROLMENT, AND IT EXISTS BECAUSE A PRIVATE
     * CONVERSATION CARRIES NO COURSE. Evaluating scope only where a course id is
     * present would leave the one surface that has none — the student's own
     * conversation — unrestricted, so an assistant confined to a single course
     * would read every private conversation in the workspace. The answer is
     * whether the student is enrolled in ANY course inside the assistant's scope.
     */
    public function mayActOnStudent(User $user, int $workspaceId, int $studentUserId): bool;

    /**
     * The courses this assistant is confined to, or `null` for no confinement.
     *
     * ⚠️ `null` AND `[]` ARE DIFFERENT ANSWERS AND MUST STAY SO. `null` is "not
     * confined" — not an assistant, or an assistant with an empty scope; `[]`
     * cannot be returned by this method at all, and a caller treating a `null`
     * as an empty list refuses everything. For display; the guards above are
     * what authorise.
     *
     * @return list<int>|null
     */
    public function scopedCourseIdsFor(User $user, int $workspaceId): ?array;
}
