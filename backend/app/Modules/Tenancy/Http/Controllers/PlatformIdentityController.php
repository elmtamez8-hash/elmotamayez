<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;

/**
 * What the product calls itself and how to reach it — the platform settings a
 * visitor may read.
 *
 * ⚠️ AN ALLOWLIST, NEVER `PlatformSettings::all()`. That table holds
 * the device limit, the grant TTL, the operating fee and the gateway's basis
 * points; a «settings» endpoint that returned the map would put the platform's
 * half of the price on a public URL, and every key added afterwards would join it
 * silently. `PublicFieldAllowlist` is what fails the build if a field nobody
 * decided on appears here — and the second one earned its place: a support number is
 * published by design, while `billing.transfer` is read by somebody about to pay
 * and stays behind authentication.
 *
 * ⚠️ AND IT IS UNAUTHENTICATED ON PURPOSE. The name is on the login page, in the
 * `<title>` of every public page and in the manifest — it is read before anybody
 * has an account, so requiring one would mean the sign-in screen could not spell
 * the product it signs you in to. The support number is read on the same
 * pages for the same reason: a visitor with no account is exactly who needs to
 * ask a question before making one.
 */
class PlatformIdentityController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'name' => (string) PlatformSettings::get('platform.name'),
                /*
                 * Digits only, or an empty string meaning «no support line».
                 *
                 * ⚠️ THE EMPTY STRING IS THE OFF SWITCH AND IT TRAVELS. A key
                 * left out of the payload when the number is blank would make
                 * the client tell «unset» from «the API is older than this
                 * field» by guessing; an empty string says it in one shape.
                 */
                'support_whatsapp' => (string) PlatformSettings::get('platform.support_whatsapp'),
                // The legal pages' identity. Empty strings, never absent keys,
                // for the reason given above.
                'legal_name' => (string) PlatformSettings::get('platform.legal_name'),
                'postal_address' => (string) PlatformSettings::get('platform.postal_address'),
                'contact_email' => (string) PlatformSettings::get('platform.contact_email'),
            ],
        ]);
    }
}
