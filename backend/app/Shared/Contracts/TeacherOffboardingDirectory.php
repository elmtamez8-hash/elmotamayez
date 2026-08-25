<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Whether the teacher behind a workspace has finished leaving the platform
 * (spec 013 · FR-037, asked from spec 010).
 *
 * ⚠️ A CONTRACT BECAUSE THE QUESTION IS ASKED ON A WRITE PATH IN ANOTHER MODULE.
 * `ConversationPolicy::post()` is the single door for both sending into an
 * existing thread and opening a new one — `StartConversation` authorises an
 * UNSAVED `Conversation` against the same ability — so a column on the thread
 * could not answer for the second case, and a second, differently-spelled guard
 * beside it is the defect `BookingEligibility` and `ListLeaderboardScopes` have
 * each already paid for.
 *
 * ⚠️ AND THE ENROLMENT CANNOT ANSWER IT. `FR-035` keeps a paying student's course
 * for the rest of their term, so nothing ends their enrolment when their teacher
 * leaves — which is exactly the condition `post()` reads. Left alone, a student
 * writes into a workspace with no member left to answer, for ever, and the
 * product tells them nothing.
 *
 * Implementations must be registered `scoped()`: the answer is asked once per
 * message and must be memoised inside a request, and must NOT survive a queue
 * worker's next job.
 */
interface TeacherOffboardingDirectory
{
    /** Has an exit for this workspace been COMPLETED (not merely requested)? */
    public function hasDeparted(int $workspaceId): bool;
}
