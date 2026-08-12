<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Http\Requests\StartPaymentRequest;
use App\Modules\Payments\Http\Resources\PaymentTransactionResource;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Start a payment for an order.
     *
     * The response carries a redirect URL or transfer instructions — never a
     * payment instrument, a token or anything resembling one (FR-003).
     */
    public function charge(
        StartPaymentRequest $request,
        Order $order,
        InitiatePayment $initiate,
        PaymentProviderRegistry $registry,
    ): JsonResponse {
        $this->authorize('pay', $order);

        $provider = $request->filled('provider')
            ? $registry->get((string) $request->input('provider'))
            : $registry->default();

        $intent = $initiate->handle($order, $provider);

        return response()->json([
            'reference' => $intent->reference,
            'method' => $intent->method->value,
            'method_label' => $intent->method->label(),
            'redirect_url' => $intent->redirectUrl,
            'instructions' => $intent->instructions,
            'amount_minor' => $intent->amountMinor,
            'currency' => $intent->currency,
        ], 201);
    }

    /** The payer's own transaction, by uuid. */
    public function show(Request $request, PaymentTransaction $transaction): PaymentTransactionResource
    {
        $this->authorize('view', $transaction);

        return new PaymentTransactionResource($transaction);
    }
}
