<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;

/**
 * What the product calls itself — the one platform setting a visitor may read.
 *
 * ⚠️ AN ALLOWLIST OF ONE FIELD, NEVER `PlatformSettings::all()`. That table holds
 * the device limit, the grant TTL, the operating fee and the gateway's basis
 * points; a «settings» endpoint that returned the map would put the platform's
 * half of the price on a public URL, and every key added afterwards would join it
 * silently. `PublicFieldAllowlist` is what fails the build if a second field ever
 * appears here.
 *
 * ⚠️ AND IT IS UNAUTHENTICATED ON PURPOSE. The name is on the login page, in the
 * `<title>` of every public page and in the manifest — it is read before anybody
 * has an account, so requiring one would mean the sign-in screen could not spell
 * the product it signs you in to.
 */
class PlatformIdentityController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'name' => (string) PlatformSettings::get('platform.name'),
            ],
        ]);
    }
}
