<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\TerminateAuthSession;
use App\Modules\Identity\Http\Resources\AuthSessionResource;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\SessionEndReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sessions = AuthSession::query()
            ->with('device')
            ->where('user_id', $this->currentUser($request)->getKey())
            ->active()
            ->latest('created_at')
            ->get();

        return response()->json(AuthSessionResource::collection($sessions));
    }

    public function destroy(Request $request, string $uuid, TerminateAuthSession $terminate): JsonResponse
    {
        $session = AuthSession::query()->where('uuid', $uuid)->firstOrFail();

        // 404, not 403: whether a session uuid exists is itself information.
        abort_unless($request->user()?->can('delete', $session) ?? false, 404);

        $terminate->handle($session, SessionEndReason::Manual);

        return response()->json(null, 204);
    }

    /**
     * Why a session ended — reachable without authentication, by design.
     *
     * The question is only ever asked after the token is gone, so requiring a
     * token would make it unanswerable. Two fields and no more: no name, no
     * device, no address. The uuid is unguessable and the client has held it
     * since sign-in, so this tells the asker nothing they did not already have.
     */
    public function endReason(string $uuid): JsonResponse
    {
        $session = AuthSession::query()->where('uuid', $uuid)->first();

        if ($session === null || $session->isActive()) {
            return response()->json(['reason' => null, 'ended_at' => null]);
        }

        return response()->json([
            'reason' => $session->ended_reason?->value,
            'ended_at' => $session->ended_at?->toIso8601String(),
        ]);
    }
}
