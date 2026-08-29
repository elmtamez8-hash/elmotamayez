<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\SetPlanPrice;
use App\Modules\Payments\Http\Resources\PlanResource;
use App\Modules\Payments\Models\Plan;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform's half of a plan (T097 · FR-025 · Q4).
 *
 * ⚠️ EVERY READ AND WRITE HERE DECLARES `withoutWorkspaceScope()` EXPLICITLY,
 * AND THE ROUTE TAKES A UUID RATHER THAN A BOUND MODEL. `WorkspaceContext::id()`
 * falls back to `users.last_workspace_id` for EVERY user, a platform officer
 * included — so route-model binding resolves plans in one arbitrary workspace of
 * theirs and answers 404 for every other teacher on the platform, while the list
 * silently shows one teacher's plans as though they were all of them. That
 * defect has already shipped once in this product, in the audit chain, and
 * answered «nothing was bought» with a 200.
 *
 * Which is also why any test of this controller needs TWO workspaces. On a
 * single-workspace fixture the scoped and unscoped versions agree perfectly.
 */
class PlanPricingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Plan::class);

        $plans = Plan::query()
            ->withoutWorkspaceScope()
            ->with('workspace')
            // Unpriced first: this screen exists to empty that queue, and a plan
            // waiting for a number is the only row on it anybody must act on.
            ->orderByRaw('CASE WHEN price_minor IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('id')
            ->paginate(50);

        return PlanResource::collection($plans)->response();
    }

    public function price(Request $request, string $uuid, SetPlanPrice $action): JsonResponse
    {
        $plan = Plan::query()->withoutWorkspaceScope()->where('uuid', $uuid)->firstOrFail();

        $this->authorize('price', $plan);

        $validated = $request->validate([
            // Nullable on purpose: clearing a price is how an officer takes a
            // plan off sale without switching off a teacher's own row.
            'price_minor' => ['present', 'nullable', 'integer', 'min:0'],
        ]);

        try {
            $priced = $action->handle($plan, $validated['price_minor'] === null ? null : (int) $validated['price_minor']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => PlanResource::make($priced)]);
    }
}
