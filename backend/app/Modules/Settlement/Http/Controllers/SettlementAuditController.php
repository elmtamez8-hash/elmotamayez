<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settlement\Http\Resources\SettlementAuditEntryResource;
use App\Modules\Settlement\Support\SettlementAuditSubjects;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Every administrative act on a teacher's money, and nothing else (FR-034).
 *
 * One permission, and it is a platform one: `SETTLEMENT_AUDIT_VIEW` reaches only
 * super-admin through `RolePermissionMatrix`'s `$all`, exactly like approving a
 * rate. A teacher does not read this — their statement already answers "what
 * happened to my money", and it answers it without listing who decided.
 *
 * Because the reader is an auditor rather than a teacher, this is the one place
 * in the module where a list is scoped by NEITHER the caller's own profile nor
 * the workspace: `activity_log` carries no `workspace_id` column and no global
 * scope, and the permission that reaches this is a platform one. Platform-wide
 * is the answer FR-034 asks for — but it is a deliberate answer, not an
 * oversight, and a second consumer must not assume a tenant filter is here.
 */
class SettlementAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // A bare permission check rather than a policy, because there is no
        // subject to build one around: this list spans six models and asks one
        // question about the reader, not about a row. `CertificateController`
        // and `AttendanceController` gate the same way.
        abort_unless($this->currentUser($request)->can(Permissions::SETTLEMENT_AUDIT_VIEW), 403);

        $entries = Activity::query()
            // The filter is the query, not a pass over its results. See
            // SettlementAuditSubjects — `activity_log` is shared with billing,
            // and asking for the table and then removing rows is one forgotten
            // branch away from showing the wrong context.
            ->whereIn('subject_type', SettlementAuditSubjects::types())
            // Morph-eager-loaded: a Resource runs once per row, so reading the
            // subject's uuid inside one is an N+1 by construction — the lesson
            // ClassSessionResource paid for in 005.
            ->with(['subject', 'causer'])
            ->latest('id')
            ->paginate(50);

        return response()->json(
            SettlementAuditEntryResource::collection($entries)->response()->getData(true)
        );
    }
}
