<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Events\ReceiptUploaded;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Http\UploadedFile;

/**
 * The first step of the declared receipt path: uploaded → under review (FR-020).
 *
 * ⚠️ THE DECIDED STATES REFUSE A RECEIPT, and that refusal is the half of FR-020
 * nothing enforced before. A "declared path" whose last step can be walked
 * backwards is not a path: a payer who uploads a second image onto an order
 * already approved would replace the document the approver actually read, and
 * the audit trail would show an approval of a file that arrived after it.
 *
 * ⚠️ THE METHOD IS RECORDED HERE AND CARRIED ON THE ORDER, not on the
 * transaction — because on the manual path no transaction exists yet. The payer
 * is the only person who knows whether they used a bank wire or a mobile wallet,
 * and the approver reading a statement weeks later is exactly who needs the
 * answer (FR-018). {@see ApproveOrder} copies it onto the transaction it mints.
 */
class UploadPaymentReceipt extends Action
{
    use LogsActivity;

    /**
     * @param  PaymentMethod  $method  what the payer actually did. `Gateway` is
     *                                 refused: a gateway payment produces its own
     *                                 transaction and needs no receipt at all,
     *                                 and accepting the claim here would let a
     *                                 payer label a wire as a settled card.
     */
    public function handle(
        Order $order,
        UploadedFile $file,
        User $user,
        PaymentMethod $method = PaymentMethod::BankTransfer,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Order {
        /*
        | 024 · FR-007 — and the rule lives HERE, not only in the policy.
        |
        | The receipt often arrives on WhatsApp from a student who never opened
        | the product, so the officer creating the order is the one holding the
        | image. Widening `OrderPolicy::uploadReceipt()` alone left this line
        | refusing them one layer down: 403 became 422, which reads as a broken
        | upload rather than a refused one. The Action is the entry point Filament
        | and any console command share, so it is where the rule has to be true.
        */
        if ($order->user_id !== $user->getKey()
            && ! ($order->requiresPlatformApproval() && $user->can(Permissions::BILLING_PURCHASE_APPROVE))) {
            throw new DomainException('You can only upload receipts for your own orders.');
        }

        if (! $order->acceptsReceipt()) {
            throw new DomainException('لا يمكن رفع إيصال على طلب صدر فيه قرار بالفعل.');
        }

        if ($method === PaymentMethod::Gateway) {
            throw new DomainException('الدفع عبر البوابة لا يحتاج إيصالاً.');
        }

        $order->addMedia($file)->toMediaCollection('receipt');

        /*
        | ⚠️ A REJECTED ORDER COMES BACK TO `under_review`, NOT TO A NEW ORDER
        | (027 · FR-032). The refusal reason, the amount that was quoted and the
        | subscription snapshot all live on this row; starting again would lose
        | all three and put a second pending order beside the first. Both decision
        | Actions claim on `whereIn('pending','under_review')`, so a re-opened
        | order is decidable again by exactly the same path.
        |
        | ⚠️ AND `rejection_reason` IS CLEARED. Left standing it reads, beside a
        | fresh receipt, as though the new one had already been refused.
        */
        $order->update([
            'status' => 'under_review',
            'rejection_reason' => null,
            'metadata' => [...($order->metadata ?? []), 'method' => $method->value],
        ]);

        $order->refresh();

        $this->logActivity('receipt.uploaded', $order, [
            'method' => $method->value,
            // The two fields FR-024 names, and the reason this Action grew a
            // LogsActivity it never had: an approval trail that records who
            // decided but not who submitted stops one link short of the payer.
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ]);

        ReceiptUploaded::dispatch($order, $user);

        return $order;
    }
}
