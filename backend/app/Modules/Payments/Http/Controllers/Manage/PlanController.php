<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\RequestPlanChange;
use App\Modules\Payments\Actions\SavePlan;
use App\Modules\Payments\Http\Requests\RequestPlanChangeRequest;
use App\Modules\Payments\Http\Requests\SavePlanRequest;
use App\Modules\Payments\Http\Resources\PlanChangeRequestResource;
use App\Modules\Payments\Http\Resources\PlanResource;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\PlanChangeRequest;
use App\Shared\Support\WorkspaceContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The teacher's half of a plan: how long it lasts and what it covers (FR-025).
 *
 * The price is not here and cannot be reached from here. It is written by
 * `SetPlanPrice` under the platform permission `plans.price`, and the only door
 * onto that Action is `PlanResource` in the panel — the HTTP one was deleted on
 * 2026-09-05 as a twin nothing called.
 */
class PlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Plan::class);

        // Scoped by the global scope, which resolves here: the reader is a
        // workspace MEMBER, unlike every student-facing read in this module.
        $plans = Plan::query()->orderedByShape()->get();

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

    /**
     * The teacher's change requests on their own plans, newest first (٠٣٦).
     *
     * ⚠️ IT LISTS DECIDED ONES TOO. A teacher who sees only what is pending has
     * no way to learn what happened to the last one from the screen they asked
     * on — the answer arrives in a notification and nowhere else, and a
     * notification is a thing that gets missed.
     */
    public function changeRequests(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Plan::class);

        $requests = PlanChangeRequest::query()
            ->with('plan')
            ->orderByDesc('requested_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => PlanChangeRequestResource::collection($requests)]);
    }

    /**
     * «سعّرتم باقتي، وأنا عايز أغيّرها» (٠٣٦, owner decision 2026-09-19).
     *
     * ⚠️ `update` IS THE ABILITY, NOT a new one. Asking to change a plan is the
     * same authority as changing it — what the platform reserves is the DECISION,
     * and that is asked of the officer in {@see DecidePlanChange}. A second
     * permission here would be a second answer to a question the policy answers.
     */
    public function requestChange(RequestPlanChangeRequest $request, string $planUuid, RequestPlanChange $action): JsonResponse
    {
        /*
        | ⚠️ RESOLVED HERE, NOT BOUND IN THE ROUTE — and the scope IS the guard on
        | this one. `/manage/…` is a workspace member's path, where
        | `WorkspaceContext` resolves and `WorkspaceScope` bites, so a foreign
        | plan is simply not found. The authorize below is the row-level half.
        */
        $plan = Plan::query()->where('uuid', $planUuid)->first();

        if ($plan === null) {
            return response()->json(['message' => 'هذه الباقة غير موجودة عندك.'], 404);
        }

        $this->authorize('update', $plan);

        try {
            $saved = $action->handle($this->currentUser($request), $plan, $request->validated());
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => PlanChangeRequestResource::make($saved)], 201);
    }

    private function save(SavePlanRequest $request, SavePlan $action, ?Plan $plan, int $status): JsonResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return response()->json(['message' => 'تعذّر تحديد مكان عملك. أعد تحميل الصفحة.'], 422);
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
