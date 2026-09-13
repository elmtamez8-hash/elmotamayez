<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditHold;
use App\Modules\Payments\Support\CreditLedger;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * ٠٣٥ — تجميدُ حصّةٍ من الرصيدِ عندَ الحجز.
 *
 * ⚠️ CALLED INSIDE THE BOOKING'S OWN TRANSACTION, never after it. A refusal has
 * to hand the seat back in the same statement that took it, and a listener runs
 * after the commit — by which time the seat is sold.
 *
 * ⚠️ THE CLAIM IS ONE CONDITIONAL UPDATE AND THE WINNER ALONE MOVES THE
 * COUNTER. Read-then-write is the race two simultaneous bookings win together:
 * both read `remaining = 1, held = 0`, both decide there is room, and a student
 * with one credit ends up holding two seats. Never `lockForUpdate()` — a no-op
 * on SQLite, so a test written around it passes locally and proves nothing
 * about the MySQL this ships to.
 *
 * ⚠️ AND THIS IS THE EXPRESSION THAT RAISES ERROR 1690. `remaining - held - 1
 * >= floor` at a zero balance is evaluated UNSIGNED in MySQL unless both
 * operands are cast, and it explodes on the first booking by any student with
 * an empty balance — which is every student before they buy. SQLite has no
 * unsigned arithmetic, so no local test can reproduce it; `held_credits` is a
 * signed column for the same reason, and its three neighbours have been since
 * 006.
 *
 * ⚠️ `workspace_id` IS ASSIGNED EXPLICITLY. The writer is a student's own
 * request and a student is a member of no workspace, so `BelongsToWorkspace`'s
 * auto-fill writes nothing at all; the settler is a queued job with no context
 * either.
 *
 * ⛔ A SUBSCRIPTION SEAT MUST NEVER REACH HERE (٠٢٧ · FR-041). The subscriber
 * has no balance to freeze, and the branch that skips it is explicit in
 * `ClaimSubscriptionSeats` rather than guessed at here.
 */
class PlaceCreditHold extends Action
{
    public function __construct(private readonly CreditLedger $ledger) {}

    /**
     * @return bool whether the credit was frozen — false means «not enough»
     */
    public function handle(CreditBalance $balance, int $classSessionId, int $credits = 1): bool
    {
        $floor = $this->ledger->floorForBalance($balance);

        $claimed = DB::table('credit_balances')
            ->where('id', $balance->getKey())
            ->whereRaw(
                'CAST(remaining_credits AS SIGNED) - CAST(held_credits AS SIGNED) - ? >= ?',
                [$credits, $floor],
            )
            ->incrementEach(['held_credits' => $credits], ['updated_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        /*
        | ⛔ `hold_seq` IS COUNTED FROM THE ROWS THAT ALREADY EXIST for this
        | (balance, session), inside the caller's transaction.
        |
        | One seat is legitimately held more than once: six places release a
        | seat, and reviving a released one is an UPDATE rather than an INSERT,
        | so «book ⇒ hold ⇒ released ⇒ settled ⇒ REVIVED» needs a second row. A
        | two-column key refuses it — written with `insertOrIgnore` that is a
        | revived seat with NO hold at all (a free session, with the nightly
        | invariant green because nothing was ever written), and written
        | directly it is a 500 on a legitimate re-booking.
        |
        | ⚠️ AND A COLLISION HERE IS LEFT TO PROPAGATE. Two workers racing on the
        | SAME seat both compute the same sequence and the second violates the
        | unique index — which rolls the outer booking transaction back, hands
        | the seat to the winner, and is the correct answer. `BookSeat`'s own
        | collision catcher covers the seat index and not this one.
        */
        $sequence = CreditHold::query()
            ->withoutWorkspaceScope()
            ->where('credit_balance_id', $balance->getKey())
            ->where('class_session_id', $classSessionId)
            ->count();

        // `forceFill` on a NEW model, not `create()` then a second save:
        // `hold_seq` is out of `$fillable` (it discriminates a unique key, the
        // `captured_order_id` rule), and inserting at the default of zero first
        // would collide on the very revival this sequence exists for. The
        // `creating` hooks still run, so `HasUuid` still fires.
        (new CreditHold)->forceFill([
            'workspace_id' => $balance->workspace_id,
            'credit_balance_id' => $balance->getKey(),
            'class_session_id' => $classSessionId,
            'student_user_id' => $balance->student_user_id,
            'credits' => $credits,
            'held_at' => now(),
            'hold_seq' => $sequence,
        ])->save();

        return true;
    }
}
