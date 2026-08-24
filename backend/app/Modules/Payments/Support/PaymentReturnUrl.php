<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

/**
 * Where a gateway sends the payer back to when it is done with them.
 *
 * ⚠️ THIS CLASS EXISTS BECAUSE THE SCREEN DID NOT. `/billing/pay/return` shipped
 * with spec 007 — it reads `?transaction=`, polls the API for the settled answer
 * and renders three real states — and **nothing anywhere built its URL**, on
 * either side. `ChargeIntent::redirectUrl` sends the payer TO the gateway; there
 * was no field, no config and no builder for the other direction. So the day a
 * real Qatari gateway is integrated, whoever wires it would have found a
 * finished screen with no inbound path and sent the payer to a URL nobody
 * registered. The repository's own rule: a surface is not done until something
 * reaches it.
 *
 * ⚠️ THE BASE IS AN ENV VAR, NOT A `platform_settings` ROW. That rule is about
 * NUMBERS an operator tunes from the panel; this is a deployment address, and a
 * row anybody with panel access could edit is a row that can point a payer —
 * mid-payment, holding a provider's redirect — at a host we do not control.
 *
 * ⚠️ AND IT IS SEPARATE FROM `app.url`, DEFAULTING TO IT. In development the
 * browser reaches the API through Next's rewrite so the two are one host; in
 * production the API and the app are different origins, and a return URL built
 * from `app.url` would land the payer on the JSON API instead of the screen.
 */
class PaymentReturnUrl
{
    /**
     * The URL for one payment attempt.
     *
     * Keyed on the TRANSACTION and not the order: an order carries as many
     * transactions as it had attempts (a student abandons a bank page and comes
     * back), and the screen has to report on the attempt the payer just made
     * rather than on whichever row a query returned first.
     */
    public function for(string $transactionUuid): string
    {
        $base = rtrim((string) config('payments.return_url_base'), '/');

        return $base.'/billing/pay/return?transaction='.urlencode($transactionUuid);
    }
}
