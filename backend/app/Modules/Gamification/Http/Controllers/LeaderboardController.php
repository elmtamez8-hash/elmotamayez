<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Gamification\Actions\ListLeaderboardScopes;
use App\Modules\Gamification\Actions\ReadLeaderboard;
use App\Modules\Gamification\Http\Requests\ReadLeaderboardRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    public function __construct(
        private readonly ReadLeaderboard $read,
        private readonly ListLeaderboardScopes $scopes,
    ) {}

    /** Which boards this reader may open — see {@see ListLeaderboardScopes}. */
    public function scopes(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->scopes->handle($this->currentUser($request))]);
    }

    public function index(ReadLeaderboardRequest $request): JsonResponse
    {
        return response()->json($this->read->handle(
            $this->currentUser($request),
            (string) $request->input('scope'),
            $request->period(),
        ));
    }
}
