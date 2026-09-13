<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Payments\Actions\UnlockSessionContent;
use App\Modules\Payments\Models\SessionUnlock;
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

        // ١ — no such session. See the class docblock for why the sentence is
        // the same one every other refusal below gives.
        if ($session === null) {
            return response()->json(['message' => 'لا يمكن فتح محتوى هذه الحصّة.'], 403);
        }

        /*
        | ٢ · ٣ · ٤ · ٥ · ٦ — resolved by ONE call to the read contract's offer,
        | which refuses a student the hour was never theirs to buy, a session
        | with no course, one never delivered, one whose material is gone, and
        | one that is open already.
        |
        | ⛔ THE ENTITLEMENT USED TO BE A PRIVATE METHOD ON THIS CONTROLLER, and
        | that was the whole defect: the door was right and the SCREEN was not.
        | `unlockOfferFor()` asked nothing about it, so the curriculum told a
        | student in another group «افتحه بخصم حصة من رصيدك» and this endpoint
        | answered 403 one press later. It is on the contract now.
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
}
