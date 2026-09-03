<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Events\ReceiptApproved;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Shared\Actions\Action;
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

    public function handle(
        Order $order,
        User $approver,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Order {
        return DB::transaction(function () use ($order, $approver, $ipAddress, $userAgent): Order {
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
                throw new DomainException('Only pending orders can be approved.');
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
