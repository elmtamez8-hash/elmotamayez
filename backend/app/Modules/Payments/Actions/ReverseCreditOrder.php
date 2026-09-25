<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Data\SubscriptionIntent;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Listeners\ActivateSubscription;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditHold;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Payments\Support\SubscriptionAccess;
use App\Shared\Actions\Action;
use App\Shared\Events\CourseAccessWithdrawn;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reversing a CREDIT sale — a package of credits, or a session-pack plan sold
 * by the hour — the money goes back and the credits it bought that are still
 * unspent go with it.
 *
 * ⛔ THESE TWO SALES HAD NO WAY BACK. `ReverseCourseOrder` refuses every kind
 * but a course; `CancelSubscription` needs a `subscriptions` row, and an hours
 * plan writes none (`ActivateSubscription::activateSessionPlan()`); and
 * `AdjustCredits` has no screen. A bank refund of either left the payment
 * `captured`, the order `approved`, and the credits spendable.
 *
 * One transaction, in the order `ReverseCourseOrder` takes, for its reasons:
 *
 *   1) the ORDER is claimed `approved → cancelled` by a conditional UPDATE — two
 *      presses both read `approved`, and the loser affects zero rows;
 *   2) the unspent credits of THIS purchase are taken back from ITS lot — the
 *      lot is claimed first and the entry posted with `drawsFromLots: false`,
 *      the rule an expiry follows (billing.md): a plain negative movement would
 *      let the drawer take soonest-expiring credits from OTHER purchases, and
 *      the student would lose credits they paid for separately;
 *   3) then `ReversePayment`, whose `PaymentReversed` tells the student and
 *      reverses a referral award. So no `RefundIssued` is fired from here — that
 *      listener takes both events, and two would reverse the award twice.
 *
 * ⚠️ CONSUMED CREDITS STAY CONSUMED. What a lot still holds is by construction
 * what was never spent — a session already charged drew from it, and a debt the
 * purchase paid off opened it smaller (`CreditLedger::openLot()`). Those were
 * sessions delivered, and the teacher has earned their fee for them.
 *
 * ⚠️ A CREDIT FROZEN FOR A BOOKED SEAT IS NOT CONSUMED, so it is taken back too
 * — and the seat it froze goes with it (owner decision 2026-09-25). Holds are
 * per balance, not per lot, so «the seats this purchase funds» is the excess:
 * whatever is held beyond what the balance keeps after the clawback. Those
 * future seats are released through `CancelBooking::release()` — the system's
 * own door, `Released`, never billable — latest session first, so the lesson
 * nearest in time is the one the student keeps if other credits cover it.
 *
 * ⚠️ AN HOURS PLAN ALSO OPENED THE COURSE (`source = session_plan`), and the
 * reversal closes that enrolment — unless a running subscription covers the
 * course, in which case the row goes back to it exactly as a reversed course
 * purchase hands it back (`SubscriptionAccess::handBackRows()`).
 *
 * ⚠️ AND THE SALE ROW IS MARKED `reversed_at`, so the finance screens that sum
 * `credit_purchases` stop counting a sale whose money went back.
 */
class ReverseCreditOrder extends Action
{
    use LogsActivity;

    /**
     * The idempotency key's type half for the clawback — ≤ 32 characters, and
     * distinct from `credit_refund` (`AdjustCredits`), whose ids are hashes.
     */
    public const CREDIT_SOURCE_TYPE = 'order_reversal';

    public function __construct(
        private readonly ReversePayment $reverse,
        private readonly CreditLedger $ledger,
        private readonly CancelBooking $bookings,
    ) {}

    /**
     * Whether this order is one this Action reverses.
     */
    public static function reverses(Order $order): bool
    {
        if ($order->kind === OrderKind::Credits) {
            return true;
        }

        if ($order->kind !== OrderKind::Subscription) {
            return false;
        }

        return SubscriptionIntent::fromOrder($order)?->isSessionShaped() === true;
    }

    /**
     * How many credits reversing this order would take back — what the
     * officer is shown before confirming. The same lookup `handle()` claims.
     */
    public function refundableFor(Order $order): int
    {
        $lot = $this->lotFor($order);

        return $lot === null ? 0 : max(0, (int) $lot->credits_remaining);
    }

    /**
     * How many future seats reversing this order would release — the second
     * number the officer is shown. The same selection `handle()` releases.
     */
    public function seatsReleasedFor(Order $order): int
    {
        $lot = $this->lotFor($order);

        return $lot === null ? 0 : $this->fundedHolds($lot, max(0, (int) $lot->credits_remaining))->count();
    }

    public function handle(Order $order, User $by, string $reason): Order
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('اكتبْ سببَ العكس.');
        }

        if (! self::reverses($order)) {
            throw new DomainException('العكسُ من هنا لشراءِ الأرصدةِ وباقاتِ الحصصِ وحدَها.');
        }

        DB::transaction(function () use ($order, $by, $reason): void {
            // Without the workspace scope: a platform officer's context falls
            // back to their own workspace (the 024 defect's third layer).
            $claimed = Order::query()
                ->withoutWorkspaceScope()
                ->whereKey($order->getKey())
                ->where('status', OrderStatus::Approved->value)
                ->update(['status' => OrderStatus::Cancelled->value]);

            if ($claimed === 0) {
                throw new DomainException('هذا الطلبُ غيرُ معتمَدٍ الآن — عُكِسَ سلفاً أو لم يُعتمَد.');
            }

            $captured = PaymentTransaction::query()
                ->withoutWorkspaceScope()
                ->where('order_id', $order->getKey())
                ->where('status', PaymentStatus::Captured->value)
                ->first();

            if ($captured === null) {
                // Rolls the whole transaction back, the claim above included.
                throw new DomainException('لا دفعةَ محصَّلةً على هذا الطلبِ لتُعكَس.');
            }

            [$clawedBack, $seatsReleased] = $this->clawBack($order, $by, $reason);

            $accessClosed = $this->closeHoursAccess($order, $by);

            // Marked once: the order claim above already decided the race.
            CreditPurchase::query()
                ->withoutWorkspaceScope()
                ->where('order_id', $order->getKey())
                ->whereNull('reversed_at')
                ->update(['reversed_at' => now()]);

            $this->reverse->handle($captured, $reason);

            $this->logActivity('order.reversed', $order, [
                'reason' => $reason,
                'reversed_by' => $by->getKey(),
                'transaction_id' => $captured->getKey(),
                'credits_clawed_back' => $clawedBack,
                'seats_released' => $seatsReleased,
                'enrollments_closed' => $accessClosed,
            ]);
        });

        return $order->refresh();
    }

    /**
     * Release the seats the purchase funds, then take its unspent credits back
     * out of its own lot.
     *
     * @return array{0: int, 1: int} credits taken back, seats released
     */
    private function clawBack(Order $order, User $by, string $reason): array
    {
        $lot = $this->lotFor($order);

        if ($lot === null) {
            return [0, 0];
        }

        $remainder = (int) $lot->credits_remaining;

        if ($remainder <= 0) {
            return [0, 0];
        }

        /*
        | ⛔ THE SEATS FIRST. A released seat settles its hold (`released`), so
        | once they are gone what is still held fits inside what remains — and a
        | seat left booked on a credit taken back would be charged at delivery,
        | a debt for a lesson the reversal was meant to end.
        */
        $released = 0;

        foreach ($this->fundedHolds($lot, $remainder) as $hold) {
            $booking = SessionBooking::query()
                ->withoutWorkspaceScope()
                ->where('class_session_id', $hold->class_session_id)
                ->where('student_user_id', $hold->student_user_id)
                ->where('status', BookingStatus::Booked->value)
                ->first();

            if ($booking !== null) {
                $this->bookings->release($booking, $reason);
                $released++;
            }
        }

        // The claim, in the shape `ExpireCreditLotsJob` claims a lot: one
        // conditional UPDATE that both checks and takes. A session charged since
        // the officer read the number moved it, and the whole reversal rolls
        // back rather than taking back a credit that was just spent.
        $claimed = CreditLot::query()
            ->withoutWorkspaceScope()
            ->whereKey($lot->getKey())
            ->where('credits_remaining', $remainder)
            ->update(['credits_remaining' => 0]);

        if ($claimed === 0) {
            throw new DomainException('تغيّر رصيدُ هذا الشراءِ أثناءَ العكس — أعِد المحاولة.');
        }

        $balance = CreditBalance::query()->withoutWorkspaceScope()->findOrFail($lot->credit_balance_id);

        $this->ledger->post(new CreditMovement(
            balance: $balance,
            type: CreditTransactionType::Refund,
            credits: -$remainder,
            sourceType: self::CREDIT_SOURCE_TYPE,
            // The idempotency key: one clawback per order, whatever replays.
            sourceId: (int) $order->getKey(),
            performedBy: (int) $by->getKey(),
            reason: $reason,
            // Money going out, as `AdjustCredits` enforces for a refund: never
            // below zero, so a stale number cannot take back what is not there.
            enforceFloor: true,
            // The lot above was emptied by the claim; letting the drawer run
            // would take the same credits again from other purchases' lots.
            drawsFromLots: false,
        ));

        return [$remainder, $released];
    }

    /**
     * The future seats this purchase's credits fund: open holds on the lot's
     * balance, beyond what the balance keeps after `$takingBack` leaves it,
     * latest session first.
     *
     * A session already started is left alone — it is being delivered, and its
     * charge is a debt the floor never refuses.
     *
     * @return Collection<int, CreditHold>
     */
    private function fundedHolds(CreditLot $lot, int $takingBack): Collection
    {
        $balance = CreditBalance::query()->withoutWorkspaceScope()->find($lot->credit_balance_id);

        if ($balance === null) {
            return collect();
        }

        $keeps = max(0, (int) $balance->remaining_credits - $takingBack);
        $excess = (int) $balance->held_credits - $keeps;

        if ($excess <= 0) {
            return collect();
        }

        $holds = CreditHold::query()
            ->withoutWorkspaceScope()
            ->join('class_sessions', 'class_sessions.id', '=', 'credit_holds.class_session_id')
            ->where('credit_holds.credit_balance_id', $balance->getKey())
            ->whereNull('credit_holds.settled_at')
            ->where('class_sessions.starts_at', '>', now())
            ->orderByDesc('class_sessions.starts_at')
            ->orderByDesc('credit_holds.id')
            ->get(['credit_holds.*']);

        $funded = collect();

        foreach ($holds as $hold) {
            if ($excess <= 0) {
                break;
            }

            $funded->push($hold);
            $excess -= (int) $hold->credits;
        }

        return $funded;
    }

    /**
     * Close the course an hours plan opened, or hand it back to a running
     * subscription that covers it.
     *
     * @return int how many enrolments were closed
     */
    private function closeHoursAccess(Order $order, User $by): int
    {
        if ($order->kind !== OrderKind::Subscription) {
            return 0;
        }

        $rows = Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $order->getKey())
            ->where('source', 'session_plan')
            ->whereIn('status', Enrollment::GRANTING_STATUSES)
            ->get(['id', 'course_id']);

        $handedBack = SubscriptionAccess::handBackRows($rows, (int) $order->getKey(), (int) $order->user_id);

        $closing = $rows->reject(
            static fn (Enrollment $row): bool => in_array((int) $row->getKey(), $handedBack, true),
        );

        if ($closing->isEmpty()) {
            return 0;
        }

        $closed = Enrollment::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $closing->pluck('id'))
            ->whereIn('status', Enrollment::GRANTING_STATUSES)
            ->update(['status' => EnrollmentStatus::Cancelled->value]);

        // As `ReverseCourseOrder`: read before the close, dispatched inside the
        // transaction to after-commit listeners (the group, the other seats).
        CourseAccessWithdrawn::dispatch(
            (int) $order->workspace_id,
            (int) $order->user_id,
            array_values(array_unique(array_map(
                static fn (mixed $id): int => (int) $id,
                $closing->pluck('course_id')->all(),
            ))),
            (int) $by->getKey(),
        );

        return $closed;
    }

    /**
     * The lot this order's purchase opened, found by the key each sale minted
     * under: a package on its `credit_purchases` row, an hours plan on the order.
     */
    private function lotFor(Order $order): ?CreditLot
    {
        $purchase = CreditPurchase::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $order->getKey())
            ->first();

        if ($purchase === null) {
            return null;
        }

        [$sourceType, $sourceId] = $order->kind === OrderKind::Credits
            ? ['credit_purchase', (int) $purchase->getKey()]
            : [ActivateSubscription::CREDIT_SOURCE_TYPE, (int) $order->getKey()];

        $entry = CreditTransaction::query()
            ->withoutWorkspaceScope()
            ->where('credit_balance_id', $purchase->credit_balance_id)
            ->where('type', CreditTransactionType::Purchase->value)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        if ($entry === null) {
            return null;
        }

        return CreditLot::query()
            ->withoutWorkspaceScope()
            ->where('credit_transaction_id', $entry->getKey())
            ->first();
    }
}
