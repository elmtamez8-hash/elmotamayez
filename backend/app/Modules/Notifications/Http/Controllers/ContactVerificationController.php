<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Actions\ConfirmContactVerification;
use App\Modules\Notifications\Actions\RequestContactVerification;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Contracts\SendsVerificationCodes;
use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Support\NotificationChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContactVerificationController extends Controller
{
    public function store(Request $request, RequestContactVerification $action): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::enum(NotificationChannel::class)],
            'contact_value' => ['required', 'string', 'max:190'],
        ]);

        $channel = NotificationChannel::from($validated['channel']);

        $issued = $action->handle(
            $this->currentUser($request),
            $channel,
            $validated['contact_value'],
        );

        $this->deliver($channel, $issued->verification->contact_value, $issued->code);

        // $issued->code is deliberately absent from this response. It reaches the
        // user over the channel being verified — which is the entire point — and
        // any channel that echoes it back over HTTP has verified nothing.
        return response()->json([
            'uuid' => $issued->verification->uuid,
            'expires_at' => $issued->verification->expires_at?->toIso8601String(),
        ], 201);
    }

    public function confirm(Request $request, string $uuid, ConfirmContactVerification $action): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $verification = ContactVerification::query()
            ->where('uuid', $uuid)
            ->where('user_id', $this->currentUser($request)->getKey())
            ->firstOrFail();

        $action->handle($verification, $validated['code']);

        return response()->json([
            'uuid' => $verification->uuid,
            'channel' => $verification->channel,
            'verified_at' => $verification->verified_at?->toIso8601String(),
        ]);
    }

    /**
     * Hand the code to the channel being verified — directly, and never through
     * DispatchNotification (spec 020, FR-015).
     *
     * ⚠️ THE ORDINARY PATH CANNOT CARRY THIS MESSAGE, BY CONSTRUCTION. Every
     * external channel's canReach() asks whether the recipient has a VERIFIED
     * contact detail, and this is by definition the message that goes to an
     * unverified one — so dispatched normally it would be skipped every time, for
     * ever, and no number on the platform could ever be verified. It would also
     * write a row into a notification feed that has nothing to do with the feed.
     *
     * Asked as a CAPABILITY rather than by name: a channel that cannot carry a
     * code (the in-app one never will — a code delivered inside the account is
     * proof of nothing) simply does not implement the interface, and the code
     * goes nowhere. That is the pre-020 behaviour, preserved exactly.
     */
    private function deliver(NotificationChannel $channel, string $contactValue, string $code): void
    {
        $registry = app(ChannelRegistry::class);

        if (! $registry->has($channel)) {
            return;
        }

        $implementation = $registry->get($channel);

        if (! $implementation instanceof SendsVerificationCodes || ! $implementation->isEnabled()) {
            return;
        }

        try {
            $implementation->sendVerificationCode($contactValue, $code);
        } catch (Throwable $e) {
            // The user is looking at a screen waiting for a code. Swallowing this
            // would leave them waiting for something that is never coming, so it
            // is surfaced — but as our sentence, not the provider's: a raw
            // upstream message on a screen is the rule this product does not
            // break, and it would leak what we send through.
            Log::warning('[notifications] verification code not delivered', [
                'channel' => $channel->value,
                'reason' => $e->getMessage(),
            ]);

            // The second sentence is not padding. Requesting a code retires the
            // previously verified number before this line runs, so a failure here
            // leaves the account unreachable — and an unreachable account that
            // was never told is the silent kind of broken.
            throw ValidationException::withMessages([
                'contact_value' => 'تعذّر إرسال رمز التأكيد إلى هذا الرقم الآن. رقمك السابق لم يعد مؤكَّداً، فأعِد المحاولة.',
            ]);
        }
    }
}
