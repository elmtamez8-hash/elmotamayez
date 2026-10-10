<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LiveSessions\Exceptions\InvalidBroadcastSignature;
use App\Modules\LiveSessions\Exceptions\UnsupportedCapability;
use App\Modules\LiveSessions\Support\BroadcastProviderResolver;
use App\Modules\LiveSessions\Support\JoinEnforcer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The broadcast provider's webhook — acted on only to turn away somebody the
 * room no longer admits (security scan 2026-10-10, F14; `JoinEnforcer`).
 *
 * ⚠️ ADDITIVE: nothing changes until the provider's dashboard points at this
 * URL. The adapter verifies the signature and reads the event; this door names
 * no provider (`ProviderNameContainmentTest`).
 *
 * ⚠️ A CONSTANT ANSWER: 401 for a bad signature, 404 when the configured
 * provider sends no such notification, 204 for everything else — an unknown
 * room, an unknown identity, an event we ignore — so the route says nothing
 * about which sessions or accounts exist.
 */
class BroadcastWebhookController extends Controller
{
    public function __invoke(Request $request, BroadcastProviderResolver $providers, JoinEnforcer $enforcer): Response
    {
        try {
            $joined = $providers->configured()->participantJoined(
                $request->getContent(),
                $request->header('Authorization'),
            );
        } catch (UnsupportedCapability) {
            abort(404);
        } catch (InvalidBroadcastSignature) {
            abort(401);
        }

        if ($joined !== null) {
            $enforcer->joined($joined['session_uuid'], $joined['identity']);
        }

        return response()->noContent();
    }
}
