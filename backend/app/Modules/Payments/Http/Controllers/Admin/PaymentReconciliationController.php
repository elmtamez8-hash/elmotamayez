<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Http\Resources\PaymentReconciliationRunResource;
use App\Modules\Payments\Models\PaymentReconciliationRun;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the last reconciliation pass found, read back.
 *
 * ⚠️ A GET THAT READS A ROW AND COMPUTES NOTHING. The sweep calls out to every
 * provider and joins the two tables that grow with every sale; on a request it
 * would fire whenever an operator opened the screen, and twice if they refreshed.
 *
 * ⚠️ AND THE ANSWER CARRIES WHEN IT LAST RAN, WHICH IS HALF OF IT. "Nothing
 * unresolved" and "the sweep has not run since Tuesday" are the same reassuring
 * screen, and the second is the one that matters: a reconciliation nobody notices
 * has stopped is a reconciliation that is not happening — which is exactly what
 * an un-expiring overlap lock produces.
 *
 * ⚠️ NO POST. Triggering a sweep from a button is a provider call and a platform
 * scan on someone's click, and the thing it would fix is fixed within the hour
 * anyway.
 */
class PaymentReconciliationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless(
            $this->currentUser($request)->can(Permissions::BILLING_COLLECTION_VIEW),
            403,
        );

        $run = PaymentReconciliationRun::query()->orderByDesc('ran_at')->first();

        // Null rather than an empty run: a platform where the sweep has never run
        // must not read as a platform that ran it and found nothing.
        return response()->json([
            'data' => $run === null ? null : PaymentReconciliationRunResource::make($run),
        ]);
    }
}
