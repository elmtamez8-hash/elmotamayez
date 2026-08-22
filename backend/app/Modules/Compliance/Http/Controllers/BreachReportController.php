<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\ReportBreach;
use App\Modules\Compliance\Http\Requests\ReportBreachRequest;
use Illuminate\Http\JsonResponse;

/**
 * The published way to report a leak (FR-040 · SC-020).
 *
 * ⚠️ THE RESPONSE IS A CONSTANT, AND THAT IS THE ENTIRE SECURITY MODEL OF THIS
 * ROUTE. It returns no uuid, no id, no count and no echo of what was sent: an
 * unauthenticated endpoint that answered differently depending on what it found
 * is an oracle, and the answers here would be worth having — whether an address
 * holds an account, whether a report about the same thing already exists, how many
 * there are. Same shape as the payment webhook's uniform `202`, and for the same
 * reason: a distinct reply tells an attacker when they are getting warm.
 *
 * ⚠️ AND A SIGNED-IN REPORTER IS RECORDED WITHOUT CHANGING THE ANSWER. The token
 * is read if one happens to be present — a member of staff reporting something
 * they found is worth being able to ask afterwards — but the route carries no
 * `auth:sanctum`, so a reporter who holds no account is refused nothing and told
 * exactly the same thing.
 */
class BreachReportController extends Controller
{
    public function store(ReportBreachRequest $request, ReportBreach $action): JsonResponse
    {
        $action->handle(
            $request->description(),
            $request->reporterContact(),
            $request->user('sanctum'),
        );

        return response()->json([
            'message' => 'وصلنا بلاغُك وسيُفحَص. شكراً لك.',
        ], 202);
    }
}
