<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\RegisterParent;
use App\Modules\Identity\Actions\StartAuthSession;
use App\Modules\Identity\Data\RegisterParentData;
use App\Modules\Identity\Http\Requests\RegisterParentRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * Parent signup only.
 *
 * Child links moved to FamilyController and notification preferences to the
 * Notifications module (spec 003) — both outgrew the shapes this controller had
 * for them: a link with no permissions, and a preference that was two booleans.
 */
class ParentController extends Controller
{
    public function register(
        RegisterParentRequest $request,
        RegisterParent $action,
        StartAuthSession $startSession,
    ): JsonResponse {
        $user = $action->handle(RegisterParentData::fromArray($request->validated()));

        // Signed in immediately: the next screen is "add your child", and a login
        // form between the two is where the flow gets abandoned.
        //
        // ⛔ THROUGH `StartAuthSession`, NEVER A BARE `createToken()`. A token
        // minted beside it has no `auth_sessions` row and no device behind it,
        // so the first sign-in of every guardian skipped the device limit and
        // the new-device alert, and the client had no `session_uuid` to ask why
        // it was later signed out. Same door as a student's signup and a login.
        $result = $startSession->handle($user, $request);

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $result->plainTextToken,
            'session_uuid' => $result->session->uuid,
        ], 201);
    }
}
