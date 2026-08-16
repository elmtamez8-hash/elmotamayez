<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\BookingEligibility;
use App\Shared\Contracts\UnlockDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * May I open this session, and if not, what exactly is missing? (FR-038)
 *
 * ⚠️ IT LIVES IN LIVESESSIONS AND THE RULE LIVES IN ASSESSMENTS, on purpose.
 * This module binds the `ClassSession` naturally and owns the route; the
 * condition is 008's and reaches here through a Shared contract — the same arrow
 * `AccountStanding` already draws. Nothing in Assessments touches a LiveSessions
 * model and nothing here touches an assignment.
 *
 * ⚠️ AND THE ANSWER IS COMPUTED BY THE SAME RESOLVER THE DOOR USES. Two
 * computations would be a student reading one reason on this screen and being
 * refused for another at the button — the disagreement nobody notices until
 * somebody quotes the screen back at their teacher.
 *
 * ⚠️ ASKED AT EVERY REQUEST, never cached against the session (FR-041): handing
 * homework in at 9pm must open the session at 9pm, with no sweep in between.
 */
class EligibilityController extends Controller
{
    public function __invoke(
        Request $request,
        ClassSession $session,
        UnlockDirectory $unlock,
        BookingEligibility $eligibility,
    ): JsonResponse {
        $this->authorize('view', $session);

        $student = $this->currentUser($request);

        $verdict = $unlock->explain($student, (int) $session->getKey());

        /*
        | The money and enrolment conditions are a separate question with a
        | separate answer, and the payload keeps them apart: a student who is
        | both short of credit AND short of homework needs to be told both, not
        | whichever the server checked first.
        */
        $other = $eligibility->refusalReason($session, $student);

        return response()->json([
            'data' => [
                'session_uuid' => $session->uuid,
                // Open only when NOTHING refuses — the two halves are ANDed here
                // rather than in either producer.
                'open' => $verdict['open'] && $other === null,
                'unlock' => $verdict,
                'booking_refusal' => $other,
            ],
        ]);
    }
}
