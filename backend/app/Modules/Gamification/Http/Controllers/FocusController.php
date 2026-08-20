<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Gamification\Actions\EndFocusSession;
use App\Modules\Gamification\Actions\StartFocusSession;
use App\Modules\Gamification\Http\Requests\StartFocusRequest;
use App\Modules\Gamification\Models\FocusSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FocusController extends Controller
{
    public function __construct(
        private readonly StartFocusSession $start,
        private readonly EndFocusSession $end,
    ) {}

    public function store(StartFocusRequest $request): JsonResponse
    {
        $session = $this->start->handle(
            $this->currentUser($request),
            (int) $request->validated('minutes'),
        );

        return response()->json($this->payload($session), 201);
    }

    /**
     * ⚠️ RESOLVED BY UUID AND FILTERED BY OWNER, not route-model bound.
     *
     * `focus_sessions` is platform-owned and carries no workspace_id, so no scope
     * stands between one student and another's row. The `user_id` filter is the
     * guard — and 404 rather than 403, because confirming that a uuid exists is
     * itself the leak.
     */
    public function end(Request $request, string $session): JsonResponse
    {
        $found = FocusSession::query()
            ->where('uuid', $session)
            ->where('user_id', $this->currentUser($request)->getKey())
            ->firstOrFail();

        return response()->json($this->payload($this->end->handle($found)));
    }

    /** @return array<string, mixed> */
    private function payload(FocusSession $session): array
    {
        return [
            'uuid' => $session->uuid,
            'planned_minutes' => $session->planned_minutes,
            'started_at' => $session->started_at->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'status' => $session->status->value,
        ];
    }
}
