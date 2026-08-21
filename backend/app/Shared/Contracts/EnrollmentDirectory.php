<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;
use App\Shared\Data\DataSubject;
use Carbon\CarbonImmutable;

/**
 * Whether a student is entitled to a course right now.
 *
 * Exists so Media can answer "may this person watch?" without reaching into
 * Learning's models, which Constitution III forbids. Same shape as
 * {@see GuardianDirectory}: Learning owns the enrolment and binds the
 * implementation, Media depends only on this interface.
 *
 * A query, not an event. Events report that something happened; this asks a
 * question that needs an answer before the next line runs.
 */
interface EnrollmentDirectory
{
    /**
     * Whether this user holds an active enrolment in this course.
     *
     * Checked on every grant issue, not once per session: an enrolment that
     * lapses mid-course must stop the next playback request (FR-010).
     */
    public function hasActiveEnrollment(User $user, int $courseId): bool;

    /**
     * Whether this user holds an active enrolment with this teacher at all.
     *
     * ⚠️ This is NO LONGER the whole eligibility question for booking.
     *
     * 005 wrote it as the whole question, on the grounds that a student studying
     * with a teacher may book any of that teacher's sessions (FR-045), and that
     * asking course by course would make eligibility depend on which course the
     * session happened to be filed under.
     *
     * Spec 006's Q-7 supersedes that. The session price is a property of the
     * course, credits are bought for a course and spent on its sessions, and a
     * session with no course has no price at all. So booking IS course-bound
     * now: BookingEligibility asks this question for enrolment AND asks
     * AccountStanding whether that specific course is withheld.
     *
     * The method keeps its meaning and its callers — it is the enrolment half.
     * It is simply no longer sufficient on its own, which is why the change is
     * recorded here rather than left for whoever writes the charge path to
     * rediscover and settle by coin toss.
     */
    public function hasActiveEnrollmentInWorkspace(User $user, int $workspaceId): bool;

    /**
     * Every course this user is actively enrolled in.
     *
     * The reason issuing grants for a list of lessons does not scale with the
     * length of the list: read entitlement once, filter in memory (SC-011).
     *
     * @return list<int>
     */
    public function activeCourseIdsFor(User $user): array;

    /**
     * Every enrolment id this user holds, whatever its status (spec 013).
     *
     * ⚠️ DELIBERATELY NOT FILTERED BY STATUS, unlike every other method here. The
     * three above answer "is this person entitled RIGHT NOW"; this one answers
     * "which rows are about this person", and a lapsed enrolment is still their
     * record — a data-rights export that silently dropped last year's course would
     * be an incomplete answer to a legal request, which is the one failure mode
     * FR-016 names.
     *
     * It exists because `lesson_progress` carries NO user column at all: it reaches
     * its student only through `enrollment_id`. `Compliance` may not query
     * `enrollments` to find that out — Constitution III — and thirteen modules each
     * resolving it for themselves is the coupling {@see DataSubject}
     * was created to prevent. One question, one owner, one answer.
     *
     * @return list<int>
     */
    public function enrollmentIdsFor(User $user): array;

    /**
     * How far into the future a workspace's paid access still runs (013 · FR-036).
     *
     * ⚠️ TWO VALUES, AND THE BOOLEAN IS THE LOAD-BEARING ONE. `expires_at` is
     * nullable and null means access that does NOT expire, which is the default
     * shape of an enrolment here — so a single "latest date" would be null for an
     * ordinary workspace and a caller reading it as "nothing left to protect"
     * would delete every recording the day its teacher left. The flag says
     * "somebody's access has no end", which no date can express.
     *
     * Asked of the WORKSPACE rather than of a person: the question is when the
     * last of a departing teacher's students loses what they paid for, and that is
     * a property of the room, not of any one seat.
     *
     * @return array{0: CarbonImmutable|null, 1: bool} the latest expiry, and
     *                                                 whether any open-ended
     *                                                 access exists
     */
    public function accessHorizonFor(int $workspaceId): array;
}
