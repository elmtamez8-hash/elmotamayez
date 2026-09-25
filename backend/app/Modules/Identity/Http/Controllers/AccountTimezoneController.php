<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\RecordAccountTimezone;
use App\Modules\Identity\Http\Requests\UpdateTimezoneRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * `PUT /me/timezone` — the clock this account reads.
 *
 * No route parameter: nothing is named, so there is no ownership check to forget.
 */
class AccountTimezoneController extends Controller
{
    public function update(UpdateTimezoneRequest $request, RecordAccountTimezone $action): JsonResponse
    {
        $user = $action->handle(
            $this->currentUser($request),
            (string) $request->validated('timezone'),
            $request->boolean('only_if_unset'),
        );

        return response()->json(UserResource::make($user));
    }
}
