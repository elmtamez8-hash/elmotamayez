<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Http\Resources\CreditBalanceResource;
use App\Modules\Payments\Http\Resources\CreditTransactionResource;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\WithholdingReader;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The student's own money, and there is no way to ask for anyone else's.
 *
 * There is deliberately no `student` parameter on either route. A parameter
 * would make "may I read this person?" a question the controller has to answer
 * correctly on every deploy; resolving the account from the bearer token makes it
 * a question nobody can ask. Same reasoning as Settlement's StatementController,
 * and the same reason a public route never binds a model implicitly.
 *
 * The guardian's route is a separate one (US4), because it takes a child and
 * therefore has to prove the relation — which is exactly the check this one gets
 * to skip.
 */
class BillingController extends Controller
{
    /**
     * One account, split by course (FR-009ج).
     *
     * Not summed. +10 in maths and −6 in physics is +4 and unblocked when added
     * up, while the design withholds per course precisely so the paid-up course
     * stays open. The sum is a wrong answer, not a compressed one.
     */
    public function balance(
        Request $request,
        CreditAccounts $accounts,
        WithholdingReader $withholding,
    ): AnonymousResourceCollection {
        $balances = $accounts->balancesFor($this->currentUser($request))
            ->load(['course', 'workspace']);

        return CreditBalanceResource::collection($withholding->stamp($balances));
    }

    /**
     * A guardian reading one of their children's balances (FR-031 · T127).
     *
     * ⚠️ THE GATE IS `childrenOf(..., Payments)`, AND THE PERMISSION IS THE
     * POINT. A guardian entitled to attendance news but not to the financial
     * record has no business seeing a ledger — asking "is this my child" and
     * then showing money would be the right relation with the wrong consent.
     *
     * ⚠️ AND THE CHILD IS MATCHED INSIDE THE AUTHORISED LIST, never fetched by
     * the uuid and then checked. `exists:users,uuid` answers a different
     * question, and a bare uuid parameter is an identity probe: the response
     * comes back carrying a name whether or not the relation exists. Same rule
     * as CreateFreezePeriod.
     */
    public function childBalance(
        Request $request,
        CreditAccounts $accounts,
        WithholdingReader $withholding,
        GuardianDirectory $guardians,
    ): AnonymousResourceCollection {
        $requested = $request->query('student');

        abort_unless(is_string($requested) && $requested !== '', 422);

        $child = $guardians
            ->childrenOf($this->currentUser($request), GuardianPermission::Payments)
            ->firstWhere('uuid', $requested);

        // 403 rather than 404: the two answers differ, and the difference tells
        // an unauthorised reader whether that uuid names a real person.
        abort_if($child === null, 403);

        $balances = $accounts->balancesFor($child)->load(['course', 'workspace']);

        return CreditBalanceResource::collection($withholding->stamp($balances));
    }

    /**
     * This student's ledger for one course, newest first.
     *
     * Filtered by the balances the ACCOUNT owns, never by an identifier arriving
     * in the request: a `balance` parameter would be an identity probe, and
     * `exists:` on it answers a different question than "is this yours".
     */
    public function transactions(Request $request, CreditAccounts $accounts): JsonResponse
    {
        $balanceIds = $accounts->balancesFor($this->currentUser($request))
            ->when(
                is_string($request->query('course')),
                fn ($balances) => $balances->filter(
                    fn ($balance): bool => $balance->course->uuid === $request->query('course'),
                ),
            )
            ->modelKeys();

        $entries = CreditTransaction::query()
            ->withoutWorkspaceScope()
            ->whereIn('credit_balance_id', $balanceIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return CreditTransactionResource::collection($entries)->response();
    }
}
