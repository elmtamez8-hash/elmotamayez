<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * ٠٣٥ — «أسقِطْ أجرَ المدرّسِ عن هذا المقعد»، من الجانبِ الذي يعرفُ كيف.
 *
 * ⛔ WITHOUT THIS HALF THE PLATFORM PAYS FOR THE EXCUSE OUT OF ITS OWN POCKET.
 * An excuse accepted inside the edit window gives the student their credit back
 * (`SessionSeatCharges::reverse()`); if the teacher keeps the unit that same
 * seat earned, the two sides of one hour stop adding up and the difference is
 * the platform's. T031 names both halves as one act.
 *
 * ⛔ AND IT IS A CONTRACT AND NOT A LISTENER, WHICH IS A MEASURED CORRECTION.
 * The first draft hung a Settlement listener off `AttendanceOverridden`;
 * `ContextIsolationTest::it('bridges to the rest of the product through
 * SessionDelivered alone')` walks `Modules/Settlement/Listeners/` and asserts
 * the set of FOREIGN events subscribed there is EXACTLY `['SessionDelivered']`
 * — so a second bridge, however well-behaved, is a red build on the import
 * line. The sanctioned shape is the one `ApprovedRateDirectory` already uses in
 * the opposite direction: a contract under `App\Shared\Contracts`, bound by
 * Settlement, resolved by the caller, and neither module naming the other.
 *
 * ⚠️ AND IT RUNS INSIDE THE CALLER'S REQUEST RATHER THAN ON A QUEUE, which the
 * listener did not. That is the point: the credit and the unit are the two
 * sides of one correction, and a queued half is a window in which the books
 * disagree with nothing to say so.
 */
interface SessionUnitReversal
{
    /**
     * Reverse the teaching unit one seat of one session earned.
     *
     * ⚠️ IDEMPOTENT BY THE UNIT'S OWN KEY, never by a status. `ReverseTeachingUnit`
     * refuses an original that IS a reversal or that carries `Reversed` — and it
     * sets NEITHER on the original it reverses, so a replay walks straight past
     * both guards onto `unique(class_session_id, student_user_id, reversal_of_id)`
     * as an unhandled QueryException.
     *
     * @return bool true when a reversal was written by THIS call
     */
    public function reverseSeat(int $classSessionId, int $studentUserId, string $reason): bool;
}
