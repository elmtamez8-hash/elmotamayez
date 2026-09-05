<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Data\SubscriptionIntent;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Events\ReceiptApproved;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A human accepts a transfer they read the receipt for.
 *
 * ⚠️ THE DECISION IS ONE ATOMIC CONDITIONAL UPDATE, the same idiom the webhook
 * capture uses. `isPending()` then `update()` is a check and a write with a
 * window between them, and two operators clicking "اعتماد" on the same order in
 * that window both passed the check — two `PaymentApproved` events, two
 * enrolments, and on a credit order two mints of the same money. The loser here
 * affects zero rows and is told the decision was already taken.
 *
 * ⚠️ AND IT WRITES `captured_order_id`, which the manual path never did. That
 * column is the engine-level "one capture per order" invariant introduced for the
 * gateway; leaving the manual path out of it left the hole open on the only path
 * that was actually in use.
 */
class ApproveOrder extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly CohortDirectory $cohorts,
    ) {}

    public function handle(
        Order $order,
        User $approver,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Order {
        return DB::transaction(function () use ($order, $approver, $ipAddress, $userAgent): Order {
            $this->assertStillActionable($order);

            /*
            | ⚠️ `withoutWorkspaceScope()` — THE THIRD LAYER OF ONE DEFECT.
            |
            | This claim is a conditional UPDATE through the model, so the global
            | scope ANDs the CURRENT workspace onto it. A platform officer's
            | context falls back to `users.last_workspace_id` like anybody
            | else's, so an officer who also owns a workspace matched ZERO rows
            | on every order outside it — and was told «Only pending orders can
            | be approved» about an order that was pending. The other two layers
            | were route-model binding (404) and `OrderPolicy` (403); all three
            | had to move, and each one hid the next.
            |
            | Safe because the row is already authorised: `OrderPolicy` ran above
            | this call and asks the workspace question itself on every
            | non-platform branch. Measured 2026-09-03 (024).
            */
            $claimed = Order::query()
                ->withoutWorkspaceScope()
                ->whereKey($order->getKey())
                ->whereIn('status', ['pending', 'under_review'])
                ->update([
                    'status' => 'approved',
                    'approved_by' => $approver->getKey(),
                    'approved_at' => now(),
                ]);

            if ($claimed === 0) {
                // ⚠️ ARABIC, LIKE EVERY OTHER SENTENCE A HUMAN READS IN THIS
                // PRODUCT. This is not an internal invariant: it is what the
                // SECOND officer sees when two press «اعتمد» at the same
                // instant, and spec 027 puts that button on a queue where a
                // simultaneous press is ordinary rather than exotic.
                throw new DomainException('اتُّخِذ القرار على هذا الطلب بالفعل.');
            }

            $this->mintTransaction($order);

            $order->refresh();

            event(new PaymentApproved($order));

            ReceiptApproved::dispatch($order, $approver);

            $this->logActivity('approved', $order, [
                'amount_minor' => $order->amount_minor,
                // FR-024 — a financial decision records the terminal it was taken
                // from, not only the account. An operator's session on a machine
                // that is not theirs is the case the address exists for.
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            ]);

            return $order;
        });
    }

    /**
     * The re-check between ordering and approving (027 · FR-026).
     *
     * ⚠️ IN THE ACTION, NOT IN THE FILAMENT SCREEN. Approval has two doors — the
     * officer's button and `POST /orders/{orderUuid}/approve` — and a guard on
     * the screen guards what is pressed while leaving what is called wide open.
     *
     * ⚠️ AND NOT IN `ActivateSubscription`. That listener is queued and runs
     * after commit, so refusing there produces literally the state FR-026 exists
     * to prevent: an approved order, money taken, and a student in no group.
     *
     * ⚠️ AND IT THROWS BEFORE THE CLAIM, so the transaction rolls back with
     * nothing in it — no status change, no payment transaction, no activity row.
     * The order is still `pending` and the officer reads a sentence naming the
     * cause.
     *
     * ⚠️ THE `PaymentCaptured` DOOR DOES NOT PASS THROUGH HERE. `ActivateSubscription`
     * is wired to both events; today every subscription order is `manual` so a
     * gateway capture cannot reach it, but the day one can, this check will not
     * have run.
     */
    private function assertStillActionable(Order $order): void
    {
        if ($order->kind !== OrderKind::Subscription) {
            return;
        }

        $intent = SubscriptionIntent::fromOrder($order);

        if ($intent === null || ! $intent->isCohort() || $intent->cohortUuid === null) {
            return;
        }

        $cohort = $this->cohorts->describeGroupCohort($intent->cohortUuid);

        if ($cohort === null) {
            throw new DomainException('المجموعة المطلوبة لم تعد موجودة. تواصل مع الطالب لاختيار مجموعة أخرى.');
        }

        if ($cohort['course_status'] !== 'published') {
            throw new DomainException('كورس هذه المجموعة لم يعد منشوراً. أعد نشره أو تواصل مع الطالب قبل الاعتماد.');
        }

        $student = $order->user;

        /*
        | ⚠️ MEMBERSHIP IS ASKED FIRST, AND ASKING JOINABILITY FIRST REFUSES EVERY
        | RENEWAL. A renewing student's group is full OF THEM AND THEIR
        | CLASSMATES, so `isJoinable()` is false for exactly the person US4·4 and
        | FR-028 promise must not be asked to choose a group again — and their
        | paid, approved order would sit `pending` for ever under «هذه المجموعة لم
        | تعد متاحة». `PurchaseSubscription::resolveCohort()` already orders the
        | two the same way; this is the same question, not a second one.
        */
        $current = $this->cohorts->openMembershipCohortId($student, (int) $cohort['course_id']);

        if ($current !== null && $current !== (int) $cohort['id']) {
            /*
            | They joined a different group between ordering and approval. Neither
            | of the two obvious answers is acceptable: `JoinCohort` would throw
            | AFTER the money committed, and `CohortMembershipWriter` would move
            | them out of the group they are in without anybody deciding it — a
            | transfer bought for the price of the cheapest plan, filed in the log
            | as a join. So the APPROVAL is refused, while it can still be refused.
            */
            throw new DomainException('الطالب مسجّل في مجموعة أخرى من هذا الكورس. يحتاج طلب نقل قبل اعتماد هذا الاشتراك.');
        }

        if ($current === (int) $cohort['id']) {
            // A renewal into their own group. There is no seat to find.
            return;
        }

        if (! $this->cohorts->isJoinable((int) $cohort['id'])) {
            throw new DomainException('لم تعد هذه المجموعة متاحة للانضمام. تواصل مع الطالب لاختيار مجموعة أخرى.');
        }
    }

    /**
     * The captured transaction this approval stands for.
     *
     * ⚠️ A GATEWAY PAYMENT MAY HAVE ALREADY CAPTURED THIS ORDER. Nothing moves
     * `orders.status` when a webhook captures — enrolment and minting hang off the
     * event, not off the column — so the order still reads `pending` and an
     * operator can reach this screen for money that has already arrived. The
     * unique index on `captured_order_id` is what makes the second capture
     * impossible; catching it here is what turns a 500 on a money path into a
     * sentence the operator can act on.
     */
    private function mintTransaction(Order $order): void
    {
        $transaction = new PaymentTransaction([
            'workspace_id' => $order->workspace_id,
            'order_id' => $order->getKey(),
            'provider' => $order->provider,
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'status' => PaymentStatus::Captured,
            'method' => $this->methodOf($order),
            'reference' => 'manual-approval-'.$order->getKey(),
            'settled_at' => now(),
        ]);

        // ONE insert carrying the claim, never an insert followed by an update:
        // the second form commits a captured transaction and only then asks
        // whether it was allowed to, so a failure leaves the row behind.
        $transaction->forceFill(['captured_order_id' => $order->getKey()]);

        try {
            $transaction->save();
        } catch (QueryException $e) {
            throw new DomainException('هذا الطلب سُدِّد بالفعل عبر بوابة الدفع.', previous: $e);
        }
    }

    /**
     * What the payer said they did, defaulting to a wire.
     *
     * Read from the order's metadata rather than asked again at approval: the
     * payer knows and the approver is guessing, and a guess written into the
     * column an operator reconciles a bank statement by is worse than the
     * conservative default.
     */
    private function methodOf(Order $order): PaymentMethod
    {
        $stored = $order->metadata['method'] ?? null;

        return (is_string($stored) ? PaymentMethod::tryFrom($stored) : null) ?? PaymentMethod::BankTransfer;
    }
}
