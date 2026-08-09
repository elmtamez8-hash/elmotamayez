<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\ListStudentBalances;
use App\Modules\Payments\Support\StudentBalanceAllowlist;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The teacher's view of who has credits left, and who is stopped.
 *
 * The workspace is the one the request is authenticated into — never an id in
 * the request. There is nothing here for anyone to substitute, which is the same
 * reason the billing-settings routes take no workspace parameter either.
 *
 * ⚠️ NO MONEY IN THE PAYLOAD, and the sweep enforces it
 * ({@see StudentBalanceAllowlist}). What a student
 * paid is the platform's price, and a teacher who could read it could solve for
 * the platform's margin from any two rows.
 */
class StudentBalanceController extends Controller
{
    public function index(Request $request, ListStudentBalances $action, WorkspaceContext $context): JsonResponse
    {
        abort_unless($this->currentUser($request)->can(Permissions::BILLING_BALANCE_VIEW), 403);

        $workspaceId = $context->id();

        // Null means a Super Admin operating globally, with no workspace chosen.
        // There is no "every teacher's students" answer to give here — the panel
        // is one teacher's — so it is a refusal rather than an empty list, which
        // would read as "you have no students".
        abort_if($workspaceId === null, 403);

        $workspace = Workspace::query()->findOrFail($workspaceId);

        return response()->json(['data' => $action->handle($workspace)]);
    }
}
