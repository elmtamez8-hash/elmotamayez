<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How much was sold at the rate about to be replaced (Q-7 · T097).
 *
 * ⚠️ IT IS A BILLING ENDPOINT THAT THE SETTLEMENT SCREEN CALLS, AND THAT SHAPE
 * IS THE POINT. The obvious version — a `credits_sold_at_old_rate` field on the
 * rate-approval payload — is a Settlement response computed from
 * `credit_purchases`, which is the exact coupling ContextIsolationTest fails the
 * build over. Two contexts, two requests, no key between them.
 *
 * What it answers is the one loss spec 006 cannot design away: a rate approved
 * BETWEEN a purchase and its delivery. Credits sold last month at last month's
 * rate are settled at this month's, and the difference falls on the platform. No
 * structure prevents it — a purchase's price is frozen (FR-021ز) and a delivered
 * session is settled at the approved rate, and both of those are correct. What
 * CAN change is whether the person pressing "approve" knows the size of it
 * first, which turns a loss discovered at close into a loss decided in advance.
 *
 * Read by SETTLEMENT_RATE_APPROVE, not by a billing permission: the reader is
 * whoever approves the rate, and requiring a second permission for a number that
 * exists solely to inform that decision would leave the screen showing nothing
 * to the one person it was built for.
 */
class OutstandingCreditsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless(
            $this->currentUser($request)->can(Permissions::SETTLEMENT_RATE_APPROVE),
            403,
        );

        $workspace = Workspace::query()
            ->where('uuid', (string) $request->query('workspace'))
            ->firstOrFail();

        $workspaceId = (int) $workspace->getKey();

        /*
        | Two different numbers, and the difference between them matters.
        |
        | `credits_sold` is what was BOUGHT here — the size of the book. What is
        | actually exposed to a rate change is what has not been delivered yet,
        | because a credit already consumed was settled at the rate in force when
        | it was taught. So the second number is the one the decision hangs on,
        | and the first is there to show what share of the book it is.
        |
        | withoutWorkspaceScope on both: the approver is a platform operator, and
        | the workspace being asked about is the one in the query string, not the
        | one they happen to be signed into.
        */
        $sold = (int) CreditPurchase::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->sum('credits');

        $outstanding = (int) CreditBalance::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('remaining_credits', '>', 0)
            ->sum('remaining_credits');

        return response()->json([
            'workspace' => $workspace->uuid,
            'credits_sold' => $sold,
            // The headline: sessions already paid for that a new rate will be
            // settled against.
            'credits_outstanding' => $outstanding,
        ]);
    }
}
