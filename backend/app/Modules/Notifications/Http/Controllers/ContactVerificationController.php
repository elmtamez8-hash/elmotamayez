<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Actions\ConfirmContactVerification;
use App\Modules\Notifications\Actions\RequestContactVerification;
use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Support\NotificationChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactVerificationController extends Controller
{
    public function store(Request $request, RequestContactVerification $action): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::enum(NotificationChannel::class)],
            'contact_value' => ['required', 'string', 'max:190'],
        ]);

        $issued = $action->handle(
            $this->currentUser($request),
            NotificationChannel::from($validated['channel']),
            $validated['contact_value'],
        );

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
}
