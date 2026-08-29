<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\SavePlan;
use App\Modules\Payments\Http\Controllers\Admin\PlanPricingController;
use App\Modules\Payments\Http\Requests\SavePlanRequest;
use App\Modules\Payments\Http\Resources\PlanResource;
use App\Modules\Payments\Models\Plan;
use App\Shared\Support\WorkspaceContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The teacher's half of a plan: how long it lasts and what it covers (FR-025).
 *
 * The price is not here and cannot be reached from here — see
 * {@see PlanPricingController}.
 */
class PlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Plan::class);

        // Scoped by the global scope, which resolves here: the reader is a
        // workspace MEMBER, unlike every student-facing read in this module.
        $plans = Plan::query()->orderBy('duration_days')->get();

        return response()->json(['data' => PlanResource::collection($plans)]);
    }

    public function store(SavePlanRequest $request, SavePlan $action): JsonResponse
    {
        $this->authorize('create', Plan::class);

        return $this->save($request, $action, null, 201);
    }

    public function update(SavePlanRequest $request, Plan $plan, SavePlan $action): JsonResponse
    {
        $this->authorize('update', $plan);

        return $this->save($request, $action, $plan, 200);
    }

    private function save(SavePlanRequest $request, SavePlan $action, ?Plan $plan, int $status): JsonResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return response()->json(['message' => 'اختر مساحة عمل أوّلاً.'], 422);
        }

        try {
            $saved = $action->handle(
                $this->currentUser($request),
                $workspaceId,
                $request->validated(),
                $plan,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => PlanResource::make($saved)], $status);
    }
}
