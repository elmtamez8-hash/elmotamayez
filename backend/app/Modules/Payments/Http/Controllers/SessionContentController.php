<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Payments\Actions\UnlockSessionContent;
use App\Modules\Payments\Models\SessionUnlock;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionContentAccess;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ٠٣٥ — `POST /class-sessions/{sessionUuid}/unlock`.
 *
 * ⛔ THE ORDER OF THE CONDITIONS IS BINDING AND EACH LINE CLOSES ONE HOLE.
 *
 * ⚠️ `{sessionUuid}` AS A STRING, RESOLVED BY HAND. The SEGMENT NAME is what
 * turns implicit binding on, whatever a comment above the route says — and
 * implicit binding here would resolve ANY session on the platform, because the
 * caller is a student and `WorkspaceScope` adds no condition for somebody whose
 * context is null. That is every self-registered student on the platform.
 *
 * ⚠️ AND THE FIRST REFUSAL IS THE SAME SENTENCE AS THE SECOND. A distinct
 * answer for «no such session» versus «not yours» is an oracle that tells the
 * asker when they are getting warm — the same reason the payment webhook and
 * the breach report answer uniformly.
 */
class SessionContentController extends Controller
{
    public function __construct(
        private readonly SessionContentAccess $access,
        private readonly EnrollmentDirectory $enrollments,
        private readonly CohortDirectory $cohorts,
    ) {}

    public function unlock(
        Request $request,
        string $sessionUuid,
        UnlockSessionContent $unlock,
    ): JsonResponse {
        $student = $this->currentUser($request);

        $session = ClassSession::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $sessionUuid)
            ->first();

        // ١ + ٢ — one refusal for both. See the class docblock.
        if ($session === null || ! $this->entitled($student, $session)) {
            return response()->json(['message' => 'لا يمكن فتح محتوى هذه الحصّة.'], 403);
        }

        /*
        | ٣ · ٤ · ٥ · ٦ — resolved by ONE call to the read contract's offer,
        | which already refuses a session with no course, one never delivered,
        | one whose material is gone, and one that is open already.
        |
        | Asked here rather than re-derived, because a second spelling of «may
        | this be sold» on the door would disagree with the one on the screen —
        | and this repository has paid for that twice, most expensively when a
        | paid-for recording became unopenable in 018.
        */
        $offer = $this->access->unlockOfferFor($student, (int) $session->getKey());

        if ($offer === null) {
            return $this->access->mayOpenSessionContent($student, (int) $session->getKey())
                // ٥ — already open. No second charge, ever (FR-011).
                ? response()->json(['message' => 'محتوى هذه الحصّة مفتوحٌ لك بالفعل.'], 409)
                : response()->json(['message' => 'لا يمكن فتح محتوى هذه الحصّة.'], 403);
        }

        if (! $offer->affordable()) {
            // ⚠️ WITH THE ROAD OUT. A refusal a student can do nothing with is
            // the shape FR-013 forbids.
            return response()->json([
                'message' => 'رصيدك لا يكفي لفتح محتوى هذه الحصّة.',
                'offer' => $offer->toArray(),
            ], 422);
        }

        try {
            $row = $unlock->handle($student, $session, SessionUnlock::REASON_CONSENT);
        } catch (DomainException $duplicate) {
            // The unique key caught a second simultaneous press — the guard
            // working, rather than a failure.
            return response()->json(['message' => $duplicate->getMessage()], 409);
        }

        return response()->json([
            'uuid' => $row->uuid,
            'credits_charged' => $row->credits_charged,
            'content_locked' => false,
        ], 200);
    }

    /**
     * A seat OF ANY STATUS, or enrolment in the course plus a cohort that can
     * see this session.
     *
     * ⚠️ «WAS EVER IN THE GROUP», NEVER «IS IN IT TODAY». The precedent is
     * `ConversationPolicy`'s `wasEverMember` — without it a student transferred
     * between cohorts is refused a session they sat in and were charged for.
     */
    private function entitled(User $student, ClassSession $session): bool
    {
        if ($session->holdsSeat($student)) {
            return true;
        }

        if ($session->course_id === null) {
            return false;
        }

        if (! $this->enrollments->hasActiveEnrollment($student, (int) $session->course_id)) {
            return false;
        }

        // An unassigned session belongs to the course rather than to a group, so
        // enrolment is the whole question there. `cohort_id` is nullable because
        // every session predating groups carries null.
        return $session->cohort_id === null
            || $this->cohorts->wasEverMember($student, (int) $session->cohort_id);
    }
}
