<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Gamification\Actions\DecideRedemption;
use App\Modules\Gamification\Actions\SaveReward;
use App\Modules\Gamification\Data\RewardData;
use App\Modules\Gamification\Enums\RedemptionStatus;
use App\Modules\Gamification\Http\Requests\SaveRewardRequest;
use App\Modules\Gamification\Http\Resources\RedemptionResource;
use App\Modules\Gamification\Http\Resources\RewardResource;
use App\Modules\Gamification\Models\Redemption;
use App\Modules\Gamification\Models\Reward;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The teacher's side: their own shop and their fulfilment queue.
 *
 * Route-model binding IS safe here — the reader is a workspace member, so
 * `WorkspaceScope` resolves and applies. That is the opposite of the student's
 * routes, where the same binding would resolve any teacher's row.
 */
class RewardController extends Controller
{
    public function __construct(
        private readonly SaveReward $save,
        private readonly DecideRedemption $decide,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Reward::class);

        return RewardResource::collection(Reward::query()->orderBy('price_coins')->get());
    }

    public function store(SaveRewardRequest $request): JsonResponse
    {
        $this->authorize('create', Reward::class);

        $workspaceId = app(WorkspaceContext::class)->id();
        abort_if($workspaceId === null, 403);

        $reward = $this->save->handle(RewardData::fromArray($request->validated()), $workspaceId);

        return RewardResource::make($reward)->response()->setStatusCode(201);
    }

    public function update(SaveRewardRequest $request, Reward $reward): RewardResource
    {
        $this->authorize('update', $reward);

        return RewardResource::make(
            $this->save->handle(RewardData::fromArray($request->validated()), (int) $reward->workspace_id, $reward),
        );
    }

    /** The teacher's queue: what has been claimed and is waiting on them. */
    public function redemptions(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Redemption::class);

        return RedemptionResource::collection(
            Redemption::query()
                ->when(
                    $request->filled('status'),
                    fn ($query) => $query->where('status', (string) $request->query('status')),
                )
                ->with(['reward', 'student'])
                ->orderByDesc('created_at')
                ->paginate(20),
        );
    }

    public function fulfill(Request $request, Redemption $redemption): JsonResponse
    {
        return $this->decideOne($request, $redemption, RedemptionStatus::Fulfilled);
    }

    public function reject(Request $request, Redemption $redemption): JsonResponse
    {
        return $this->decideOne($request, $redemption, RedemptionStatus::Rejected);
    }

    private function decideOne(Request $request, Redemption $redemption, RedemptionStatus $outcome): JsonResponse
    {
        $this->authorize('decide', $redemption);

        $decided = $this->decide->handle($redemption, $this->currentUser($request), $outcome);

        /*
         * ⚠️ ALREADY DECIDED IS A 409, NOT A SILENT SUCCESS. The claim is atomic,
         * so a second click changes nothing — but answering 200 would tell a
         * teacher who double-clicked "reject" that both went through, which is
         * exactly the impression that makes someone go looking for the coins
         * twice.
         */
        if (! $decided) {
            return response()->json([
                'message' => 'هذا الطلب حُسم سلفاً.',
                'code' => 'already_decided',
            ], 409);
        }

        return RedemptionResource::make($redemption->refresh()->load('reward'))->response();
    }
}
