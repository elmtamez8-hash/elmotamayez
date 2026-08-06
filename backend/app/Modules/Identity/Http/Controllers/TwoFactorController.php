<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\CompleteTwoFactorChallenge;
use App\Modules\Identity\Actions\ConfirmTwoFactor;
use App\Modules\Identity\Actions\DisableTwoFactor;
use App\Modules\Identity\Actions\EnableTwoFactor;
use App\Modules\Identity\Actions\RegenerateRecoveryCodes;
use App\Modules\Identity\Actions\StartAuthSession;
use App\Modules\Identity\Http\Requests\TwoFactorChallengeRequest;
use App\Modules\Identity\Http\Requests\TwoFactorConfirmRequest;
use App\Modules\Identity\Http\Requests\TwoFactorDisableRequest;
use App\Modules\Identity\Http\Requests\TwoFactorSetupRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Support\TwoFactorCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    /**
     * State only — never the secret, never the codes.
     *
     * A screen that re-displays the secret makes every read of this endpoint as
     * dangerous as the enrolment itself, and recovery codes are stored hashed
     * precisely so that this answer cannot exist.
     */
    public function show(Request $request, TwoFactorCodes $codes): JsonResponse
    {
        $user = $this->currentUser($request);
        $settings = $user->securitySettings;

        return response()->json([
            'enabled' => $user->hasTwoFactorEnabled(),
            'confirmed_at' => $settings?->two_factor_confirmed_at?->toIso8601String(),
            'required_at' => $settings?->two_factor_required_at?->toIso8601String(),
            'recovery_codes_remaining' => $codes->remainingRecoveryCodes($user),
        ]);
    }

    public function setup(TwoFactorSetupRequest $request, EnableTwoFactor $action): JsonResponse
    {
        $request->ensureCurrentPasswordIsValid();

        return response()->json([
            'otpauth_uri' => $action->handle($this->currentUser($request)),
        ]);
    }

    public function confirm(TwoFactorConfirmRequest $request, ConfirmTwoFactor $action): JsonResponse
    {
        $user = $this->currentUser($request);

        return response()->json([
            // Shown once. There is no endpoint that can show them again.
            'recovery_codes' => $action->handle(
                $user,
                (string) $request->validated('code'),
                $this->currentTokenId($request),
            ),
        ]);
    }

    public function destroy(TwoFactorDisableRequest $request, DisableTwoFactor $action): JsonResponse
    {
        $request->ensureCurrentPasswordIsValid();

        $user = $this->currentUser($request);

        $action->handle($user, (string) $request->validated('code'), $this->currentTokenId($request));

        return response()->json(null, 204);
    }

    public function recoveryCodes(Request $request, RegenerateRecoveryCodes $action): JsonResponse
    {
        return response()->json([
            'recovery_codes' => $action->handle($this->currentUser($request)),
        ]);
    }

    /**
     * The second half of signing in.
     *
     * The token is minted by StartAuthSession, exactly as an ordinary sign-in
     * does, so the device limit and its alert apply here too.
     */
    public function challenge(
        TwoFactorChallengeRequest $request,
        CompleteTwoFactorChallenge $action,
        StartAuthSession $startSession,
    ): JsonResponse {
        $user = $action->handle(
            (string) $request->validated('challenge'),
            $request->validated('code'),
            $request->validated('recovery_code'),
        );

        $result = $startSession->handle($user, $request);

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $result->plainTextToken,
            'session_uuid' => $result->session->uuid,
        ]);
    }
}
