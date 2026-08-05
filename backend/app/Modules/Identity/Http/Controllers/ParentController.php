<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\RegisterParent;
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
    public function register(RegisterParentRequest $request, RegisterParent $action): JsonResponse
    {
        $user = $action->handle(RegisterParentData::fromArray($request->validated()));

        // Signed in immediately: the next screen is "add your child", and a login
        // form between the two is where the flow gets abandoned.
        return response()->json([
            'user' => UserResource::make($user),
            'token' => $user->createToken('auth-token')->plainTextToken,
        ], 201);
    }
}
