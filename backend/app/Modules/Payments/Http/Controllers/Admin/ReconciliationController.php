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
 *
 * ⚠️ AND FOR ITS FIRST FOUR MONTHS NOBODY COULD NOTICE EITHER WAY — this route
 * had no client at all. The nightly sweep wrote a row every night and the only
 * reader of that table was a test. `/manage/billing/reconciliation` is the screen
 * now, beside its payments twin.
 */
class ReconciliationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /*
        | ⚠️ `billing.collection.view`, AND IT WAS `billing.pricing.manage` — a
        | READ of the platform's ledger behind the door for EDITING the platform's
        | cut. The two questions have nothing to do with each other, and the
        | consequence is not theoretical: an officer given the collection report
        | (its twin `PaymentReconciliationController` asks exactly this permission)
        | was refused this one, while granting them this one meant handing over the
        | six pricing keys as well. Same permission as the payments sweep, because
        | it is the same job wearing a different table.
        */
        abort_unless(
            $this->currentUser($request)->can(Permissions::BILLING_COLLECTION_VIEW),
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
