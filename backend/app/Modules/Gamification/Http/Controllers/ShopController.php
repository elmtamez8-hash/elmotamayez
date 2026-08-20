<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Gamification\Actions\RedeemReward;
use App\Modules\Gamification\Exceptions\RedemptionRefused;
use App\Modules\Gamification\Http\Resources\RedemptionResource;
use App\Modules\Gamification\Http\Resources\RewardResource;
use App\Modules\Gamification\Models\Redemption;
use App\Modules\Gamification\Models\Reward;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The student's side of the shop.
 *
 * ⚠️ EVERY QUERY HERE FILTERS EXPLICITLY, AND THE TRAIT IS NOT THE GUARD. A
 * student belongs to no workspace, so `WorkspaceContext::id()` is null,
 * `WorkspaceScope::apply()` returns early adding no condition, and
 * `BelongsToWorkspace` protects nothing on any route a student can reach. The
 * enrolment check and the `user_id` filter below are the guards.
 */
class ShopController extends Controller
{
    public function __construct(
        private readonly RedeemReward $redeem,
        private readonly EnrollmentDirectory $enrollments,
    ) {}

    /** One teacher's shop, and only if the student studies with them (FR-037). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $student = $this->currentUser($request);

        $workspace = Workspace::query()->where('uuid', (string) $request->query('workspace'))->first();

        abort_if(
            $workspace === null
            || ! $this->enrollments->hasActiveEnrollmentInWorkspace($student, (int) $workspace->getKey()),
            403,
            'لست مسجَّلاً عند هذا المدرّس.',
        );

        $rewards = Reward::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->orderBy('price_coins')
            ->get();

        return RewardResource::collection($rewards);
    }

    public function redeem(Request $request, string $reward): JsonResponse
    {
        try {
            $redemption = $this->redeem->handle($this->currentUser($request), $reward);
        } catch (RedemptionRefused $refusal) {
            /*
             * 422 with a reason the student can act on (FR-033). Never a raw
             * exception message on the screen — `userMessage()` on the frontend
             * only has to translate what it is given, and this one is already a
             * sentence.
             */
            return response()->json([
                'message' => $refusal->getMessage(),
                'code' => $refusal->reason,
            ], 422);
        }

        return RedemptionResource::make($redemption->load('reward'))
            ->response()
            ->setStatusCode(201);
    }

    /** The student's own requests. */
    public function redemptions(Request $request): AnonymousResourceCollection
    {
        $student = $this->currentUser($request);

        return RedemptionResource::collection(
            Redemption::query()
                ->withoutWorkspaceScope()
                // ⚠️ THE `user_id` FILTER IS THE WHOLE GUARD. Without it this
                // returns every redemption on the platform: the scope is inert for
                // the very people this route serves.
                ->where('user_id', $student->getKey())
                ->with('reward')
                ->orderByDesc('created_at')
                ->paginate(20),
        );
    }
}
