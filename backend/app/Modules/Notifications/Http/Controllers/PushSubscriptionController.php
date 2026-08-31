<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Actions\ForgetPushSubscription;
use App\Modules\Notifications\Actions\SavePushSubscription;
use App\Modules\Notifications\Http\Requests\SavePushSubscriptionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Register and forget one device (spec 012 · US2 · FR-010).
 *
 * ⚠️ `204` IN ALL FOUR CASES — created, updated, deleted something, deleted
 * nothing. The uniformity is the security property, not tidiness: `201` against
 * `200` is an ORACLE, telling whoever asks whether that endpoint is already
 * registered to this account, and `404` against `204` on delete tells them the
 * same thing from the other side. An endpoint is a device identifier; answering
 * «yes, this phone is already registered here» to anyone holding one is exactly
 * what the compound unique key was chosen to make impossible.
 *
 * ⚠️ AND NO uuid IS EVER RETURNED. The subscription is not addressable from
 * outside: cancelling sends the `endpoint` back, and the only other deletion
 * comes from the push service's own `410`. A route taking a subscription uuid is
 * the question «which row?» asked of the client.
 */
class PushSubscriptionController extends Controller
{
    public function store(SavePushSubscriptionRequest $request, SavePushSubscription $action): Response
    {
        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}, user_agent?: string|null} $data */
        $data = $request->validated();

        $action->handle(
            $this->currentUser($request),
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['user_agent'] ?? $request->userAgent(),
        );

        return response()->noContent();
    }

    public function destroy(Request $request, ForgetPushSubscription $action): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        $action->handle($this->currentUser($request), $validated['endpoint']);

        return response()->noContent();
    }
}
