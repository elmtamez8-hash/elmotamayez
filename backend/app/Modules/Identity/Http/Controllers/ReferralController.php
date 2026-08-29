<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\IssueReferralCode;
use App\Modules\Identity\Http\Resources\ReferralResource;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Support\ReferralStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * My invitation code, and what became of the invitations (spec 011 · FR-018 · FR-019).
 *
 * ⚠️ EVERY READ NAMES ITS OWNER EXPLICITLY. `referrals` carries no
 * `BelongsToWorkspace` — a code belongs to a person, not a classroom — so no
 * global scope stands behind these queries, and a caller is a STUDENT, for whom
 * `WorkspaceContext::id()` is null and `WorkspaceScope` adds no condition even
 * where the trait exists. `where('referrer_user_id', …)` is the entire guard,
 * and dropping it returns every referral on the platform. Same lesson as spec
 * 009's `GET /gamification/redemptions`.
 */
class ReferralController extends Controller
{
    public function code(Request $request, IssueReferralCode $action): JsonResponse
    {
        $user = $this->currentUser($request);
        $code = $action->handle($user);

        $completed = Referral::query()
            ->where('referrer_user_id', $user->getKey())
            ->where('status', ReferralStatus::Completed->value)
            ->count();

        return response()->json([
            'code' => $code->code,
            // Counted rather than paginated: the page's headline is «how many
            // people you brought», and it is one number the list below already
            // contains — but the list is paginated and a client summing a page
            // would report the page.
            'completed_count' => $completed,
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $referrals = Referral::query()
            ->where('referrer_user_id', $this->currentUser($request)->getKey())
            ->latest('id')
            ->paginate(20);

        return ReferralResource::collection($referrals);
    }
}
