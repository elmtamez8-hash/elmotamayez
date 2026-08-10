<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\CreditReconciliationRun;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the nightly reconciliation found, read back.
 *
 * ⚠️ IT READS A TABLE AND COMPUTES NOTHING. The checks are three `GROUP BY`s
 * over the fastest-growing tables of this phase with no tenant filter — run on a
 * GET they would fire whenever anyone opened the screen, and twice if they
 * refreshed it.
 *
 * ⚠️ AND THE ANSWER CARRIES WHEN IT LAST RAN, WHICH IS HALF OF IT. "No findings"
 * and "the sweep has not run since Tuesday" are the same empty list, and the
 * second is the one that matters: a reconciliation nobody notices has stopped is
 * a reconciliation that is not happening.
 */
class ReconciliationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless(
            $this->currentUser($request)->can(Permissions::BILLING_PRICING_MANAGE),
            403,
        );

        $run = CreditReconciliationRun::query()->latest('ran_at')->first();

        // Null rather than an empty run: a platform where the sweep has never
        // run must not read as a platform that ran it and found nothing.
        return response()->json(['data' => $run === null ? null : [
            'ran_at' => $run->ran_at->toIso8601String(),
            'balances_checked' => $run->balances_checked,
            'sessions_checked' => $run->sessions_checked,
            'findings_count' => $run->findings_count,
            // The stored sample, which is capped. `findings_count` is the true
            // total either way, so a screen showing 200 rows under a count of
            // 9,000 is telling the truth about both.
            'findings' => $run->findings ?? [],
        ]]);
    }
}
