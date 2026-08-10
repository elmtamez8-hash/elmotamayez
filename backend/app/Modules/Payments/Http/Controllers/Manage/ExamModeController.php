<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\ManageExamModeWindow;
use App\Modules\Payments\Http\Requests\StoreExamModeWindowRequest;
use App\Modules\Payments\Models\ExamModeWindow;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Exam mode, over the teacher's own calendar.
 *
 * The workspace is the one the request is authenticated into — never an id in
 * the payload. There is nothing here for anyone to substitute, the same reason
 * the billing-settings routes take none either.
 *
 * ⚠️ NO UUID ON THE DELETE, and that is the decision. The teacher's question is
 * "turn exam mode off", not "retire window 3f2a…" — and answering it one row at a
 * time would leave a second overlapping window quietly in force while the screen
 * showed it as off. The Action closes everything covering today.
 */
class ExamModeController extends Controller
{
    /**
     * The window in force, or null.
     *
     * Not in the task list and added deliberately: the panel screen cannot show a
     * state it has no way to read, and a switch that cannot report its own
     * position is a switch a teacher flips twice.
     */
    public function show(Request $request, WorkspaceContext $context): JsonResponse
    {
        $workspaceId = $this->workspaceFor($request, $context);

        $window = ExamModeWindow::query()
            ->where('workspace_id', $workspaceId)
            ->covering(now())
            ->first();

        return response()->json(['data' => $window === null ? null : [
            'uuid' => $window->uuid,
            'starts_on' => $window->starts_on->toDateString(),
            'ends_on' => $window->ends_on->toDateString(),
        ]]);
    }

    public function store(
        StoreExamModeWindowRequest $request,
        ManageExamModeWindow $action,
        WorkspaceContext $context,
    ): JsonResponse {
        $workspace = Workspace::query()->findOrFail($this->workspaceFor($request, $context));

        $window = $action->handle(
            $workspace,
            CarbonImmutable::parse($request->string('starts_on')->toString()),
            CarbonImmutable::parse($request->string('ends_on')->toString()),
            $this->currentUser($request),
        );

        return response()->json(['data' => [
            'uuid' => $window?->uuid,
            'starts_on' => $window?->starts_on->toDateString(),
            'ends_on' => $window?->ends_on->toDateString(),
        ]], 201);
    }

    public function destroy(
        Request $request,
        ManageExamModeWindow $action,
        WorkspaceContext $context,
    ): JsonResponse {
        $workspace = Workspace::query()->findOrFail($this->workspaceFor($request, $context));

        $action->handle($workspace, performedBy: $this->currentUser($request));

        return response()->json(['data' => null]);
    }

    /** The authenticated workspace, refused rather than guessed. */
    private function workspaceFor(Request $request, WorkspaceContext $context): int
    {
        abort_unless($this->currentUser($request)->can(Permissions::BILLING_EXAM_MODE_MANAGE), 403);

        $workspaceId = $context->id();

        // Null is a Super Admin operating globally. There is no "every teacher's
        // exam mode" to open, so it is a refusal rather than a silent no-op.
        abort_if($workspaceId === null, 403);

        return $workspaceId;
    }
}
