<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\ExecuteTeacherOffboarding;
use App\Modules\Compliance\Enums\OffboardingStatus;
use App\Modules\Compliance\Http\Resources\TeacherOffboardingResource;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\Compliance\Policies\TeacherOffboardingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The officer's half of a teacher's exit (spec 013 · FR-032 · SC-013).
 *
 * ⚠️ AN ENDPOINT RATHER THAN AN ACTION NOBODY CAN CALL. `billing.credits.adjust`
 * is the precedent for a platform permission with no HTTP surface — but this one
 * has a queue behind it: `OffboardingStatus::SettlementPending` exists as a STATE
 * rather than a flag precisely so the wait is visible on a screen, and a state
 * that no screen can reach is a comment.
 *
 * ⚠️ AND THE READ DECLARES `withoutWorkspaceScope()`. This is a PLATFORM list, and
 * `WorkspaceContext::id()` falls back to `users.last_workspace_id` for everybody —
 * an officer included — so left scoped it shows one teacher's exit and calls it
 * the platform's queue. It would pass its own test on any single-workspace
 * fixture, which is exactly how the audit chain shipped the same defect.
 */
class OffboardingController extends Controller
{
    public function __construct(private readonly TeacherOffboardingPolicy $policy) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorise($request);

        $offboardings = TeacherOffboarding::query()
            ->withoutWorkspaceScope()
            ->where('status', '!=', OffboardingStatus::Completed->value)
            ->orderBy('notice_ends_at')
            ->limit(200)
            ->get();

        return TeacherOffboardingResource::collection($offboardings);
    }

    public function execute(
        Request $request,
        TeacherOffboarding $teacherOffboarding,
        ExecuteTeacherOffboarding $action,
    ): JsonResponse {
        $this->authorise($request);

        /*
        | The refusals — money outstanding, notice not yet run out — are thrown by
        | the Action as `OffboardingNotSettled`, which `bootstrap/app.php` renders
        | as a 422 carrying its own sentence. Catching them here to reshape the
        | response would be a second place the wording lives.
        */
        $completed = $action->handle($teacherOffboarding, $this->currentUser($request));

        return TeacherOffboardingResource::make($completed)->response();
    }

    private function authorise(Request $request): void
    {
        if (! $this->policy->execute($this->currentUser($request))) {
            throw new AccessDeniedHttpException('لا تملك صلاحية لهذا الإجراء.');
        }
    }
}
