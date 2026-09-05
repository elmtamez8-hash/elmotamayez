<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\ListPlans;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Http\Resources\OrderResource;
use App\Modules\Payments\Http\Resources\PlanResource;
use App\Modules\Payments\Http\Resources\SubscriptionResource;
use App\Modules\Payments\Models\Subscription;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's side: what a teacher sells, buying it, and reading what they hold.
 *
 * ⚠️ EVERY READ HERE FILTERS BY `student_user_id` EXPLICITLY, AND THE FILTER IS
 * THE WHOLE GUARD. A student is a member of no workspace, so
 * `WorkspaceContext::id()` is null and `WorkspaceScope::apply()` adds NO
 * condition — `BelongsToWorkspace` on `Subscription` protects nothing at all on
 * this path. An unfiltered list here would return every subscription on the
 * platform, exactly as spec 009's redemption list nearly did.
 */
class SubscriptionController extends Controller
{
    /**
     * What the teacher behind this course sells.
     *
     * The COURSE is the identifier, because it is the one a student already
     * holds — `/billing/packages?course=` takes the same one for the same reason.
     */
    public function plans(Request $request, ListPlans $action): JsonResponse
    {
        $validated = $request->validate([
            'course' => ['required', 'uuid'],
        ]);

        try {
            $plans = $action->handle((string) $validated['course']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => PlanResource::collection($plans)]);
    }

    /** The subscriptions this student holds, live ones and finished ones. */
    public function index(Request $request): JsonResponse
    {
        $subscriptions = Subscription::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $this->currentUser($request)->getKey())
            ->with(['plan', 'workspace'])
            ->orderByDesc('starts_on')
            ->get();

        return response()->json(['data' => SubscriptionResource::collection($subscriptions)]);
    }

    /**
     * Buy one. The answer is an ORDER, not a subscription — the money has not
     * arrived yet, and a subscription written here would be a month of access
     * handed out against a bank transfer that may never clear.
     */
    public function store(Request $request, PurchaseSubscription $action): JsonResponse
    {
        $validated = $request->validate([
            'plan_uuid' => ['required', 'uuid'],
        ]);

        try {
            $order = $action->handle($this->currentUser($request), (string) $validated['plan_uuid']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => OrderResource::make($order)], 201);
    }
}
