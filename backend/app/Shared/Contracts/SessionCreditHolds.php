<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;
use App\Shared\Data\CreditHoldResult;

/**
 * ٠٣٥ — «الحجزُ يُجمِّدُ الرصيدَ ولا يخصمُه»، من الجانبِ الذي لا يعرفُ الفوترة.
 *
 * ⚠️ A WRITE CONTRACT, AND THE PRECEDENT IS ALREADY HERE — the claim that this
 * would be the first one in the repository did not survive a `grep`: there are
 * eighteen contracts, and `PersonalDataOwner::erase()` and `::expire()`
 * (`PersonalDataOwner.php:81,104`) both write and delete, across eleven
 * modules. So the decision needs no novelty argument; it needs a TRANSACTION
 * argument, and it has one: the hold is placed INSIDE `BookSeat`'s own
 * transaction so that a refused balance hands the seat back in the same
 * statement that took it. An event could not do that — a listener runs after
 * the commit, by which time the seat is sold.
 *
 * ⚠️ AND IT IS THE CARRIER FOR ALL SEVEN RELEASE DOORS, which is why no new
 * `Payments\Events\*` class exists for this shipment: `ContextIsolationTest`
 * forbids inside `Modules/Settlement/` every bare BASENAME of a file in
 * `Modules/Payments/Events/` (`:152-155`), and FR-032 is adding seat vocabulary
 * to the teacher's field allowlist in the same change — the two halves would
 * fail each other's build. An event with no listener would be scaffolding that
 * breaks a guard.
 *
 * ⚠️ RELEASING IS NOT AN ENTRY, SO NO LEDGER INDEX ABSORBS A DOUBLE RELEASE.
 * Every settlement is therefore ONE conditional UPDATE — `WHERE id = ? AND
 * settled_at IS NULL` — because the settler is a listener AND a periodic sweep
 * working the same seats BY DESIGN. `lockForUpdate()` is banned: a no-op on
 * SQLite, so a test written around it is green locally and proves nothing.
 *
 * ⚠️ AND RESCHEDULING IS NOT A RELEASE. `DecideSessionRescheduleRequest`
 * moves `starts_at` on the SAME session row through `UpdateClassSession`, so
 * the booking, the seat and the hold all keep their identities and the hold
 * simply rides along. Adding the reschedule to a caller list in good faith
 * hands out one free session per postponement.
 */
interface SessionCreditHolds
{
    /**
     * Freeze one credit for this seat. Called INSIDE the booking transaction.
     *
     * Scalars only, and a root `User`: the caller is LiveSessions, which may
     * not name a Payments type in any form.
     *
     * ⚠️ A SUBSCRIPTION SEAT MUST NOT REACH THIS AT ALL (٠٢٧ · FR-041): the
     * subscriber has no balance to freeze, and the branch that skips it is
     * explicit in `ClaimSubscriptionSeats` rather than guessed at here.
     */
    public function place(User $student, int $classSessionId, int $courseId, int $workspaceId): CreditHoldResult;

    /**
     * Give the frozen credits back — the seat was released before it was judged.
     *
     * ⚠️ THE BULK FORM IS TWO STATEMENTS WHATEVER THE SEAT COUNT: stamp every
     * live hold of the session, then set each balance's counter FROM A
     * SUBQUERY rather than decrementing it. An absolute write is what makes a
     * double release a no-op; cancelling a thirty-seat session must not be
     * sixty statements.
     *
     * @param  list<int>  $studentUserIds  empty means every live hold on the session
     * @return int how many holds this call actually settled
     */
    public function release(int $classSessionId, array $studentUserIds = []): int;

    /**
     * How many credits this student has frozen for this course, how many are
     * left to spend, and when the soonest frozen one is expected back.
     *
     * ⚠️ `available` TRAVELS WITH THE OTHER TWO ON PURPOSE. The one caller that
     * needs a release date is the booking refusal, and it needs the number the
     * refusal is ABOUT in the same breath — `remaining - held`, which is not a
     * column and which a caller computing it for itself would be the second
     * spelling of. One read, three facts.
     *
     * @return array{held: int, available: int, first_release_at: string|null}
     */
    public function heldFor(User $student, int $courseId): array;
}
