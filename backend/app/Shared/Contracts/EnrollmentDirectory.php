<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;

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
     * Booking a session is not tied to one course — a student studying with a
     * teacher may book any of that teacher's sessions (FR-045). Asking course by
     * course would make eligibility depend on which course the session happened
     * to be filed under, which is not a rule anyone stated.
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
}
