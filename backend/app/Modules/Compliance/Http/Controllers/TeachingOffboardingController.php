<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\RequestTeacherOffboarding;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Http\Resources\DataRequestResource;
use App\Modules\Compliance\Http\Resources\TeacherOffboardingResource;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\Compliance\Policies\TeacherOffboardingPolicy;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The teacher's own view of leaving (spec 013 · US6 · FR-034).
 *
 * ⚠️ US6 SHIPPED WITH NO ENDPOINT A TEACHER COULD REACH — the whole flow sat
 * behind a platform permission while the user story reads "a teacher asks to
 * leave". This controller is that half, and it deliberately has no `execute`:
 * completion revokes access, ends memberships and fixes the recordings' retention,
 * and FR-032 makes it conditional on money settled in both directions. A teacher
 * pressing their own complete button would be signing off on their own settlement.
 */
class TeachingOffboardingController extends Controller
{
    public function __construct(private readonly TeacherOffboardingPolicy $policy) {}

    /** Where this teacher's exit stands, or `null` if they have not asked. */
    public function show(Request $request): JsonResponse
    {
        $workspace = $this->ownedWorkspace($request);

        $offboarding = TeacherOffboarding::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspace)
            ->latest('id')
            ->first();

        return response()->json([
            'data' => $offboarding === null
                ? null
                : (new TeacherOffboardingResource($offboarding))->toArray($request),
        ]);
    }

    public function store(Request $request, RequestTeacherOffboarding $action): JsonResponse
    {
        $user = $this->currentUser($request);
        $workspaceId = $this->ownedWorkspace($request);

        $workspace = app(WorkspaceContext::class)->current();

        if ($workspace === null || (int) $workspace->getKey() !== $workspaceId) {
            throw new AccessDeniedHttpException('لا تملك صلاحية لهذا الإجراء.');
        }

        $offboarding = $action->handle($workspace, $user);

        return response()->json(
            ['data' => (new TeacherOffboardingResource($offboarding))->toArray($request)],
            $offboarding->wasRecentlyCreated ? 201 : 200,
        );
    }

    /**
     * The archive of everything this teacher authored (FR-034).
     *
     * ⚠️ IT RETURNS THE EXPORT REQUEST, NOT A FILE AND NOT A URL. The content copy
     * IS an ordinary export — `CoursesPersonalData::export()` walks a subject's
     * workspaces, which is why US3 wrote it that way — so the client links to the
     * download route that already mints a signature per press and dies five minutes
     * later. A second download path would be a second place to get the expiry
     * wrong.
     */
    public function content(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $this->ownedWorkspace($request);

        $export = DataRequest::query()
            ->where('subject_user_id', $user->getKey())
            ->where('type', DataRequestType::Export->value)
            ->whereIn('status', [
                DataRequestStatus::Pending->value,
                DataRequestStatus::Processing->value,
                DataRequestStatus::Completed->value,
            ])
            ->latest('id')
            ->first();

        return response()->json([
            'data' => $export === null
                ? null
                : (new DataRequestResource($export))->toArray($request),
        ]);
    }

    /**
     * The id of the workspace this caller OWNS, or a 403.
     *
     * ⚠️ THE OWNER, NOT A MEMBER. An assistant holding a broad role inside a
     * teacher's workspace would otherwise be able to wind the whole business down
     * — students notified, listing pulled, exit queued — over somebody else's
     * livelihood. `WorkspaceContext` answers which workspace the request is in;
     * `owner_user_id` answers whose it is, and only the second one is authority.
     */
    private function ownedWorkspace(Request $request): int
    {
        $user = $this->currentUser($request);
        $workspace = app(WorkspaceContext::class)->current();

        if ($workspace === null || ! $this->policy->request($user, (int) $workspace->owner_user_id)) {
            throw new AccessDeniedHttpException('لا تملك صلاحية لهذا الإجراء.');
        }

        return (int) $workspace->getKey();
    }
}
