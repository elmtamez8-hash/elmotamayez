<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Actions\ApproveTeacherApplication;
use App\Modules\Marketplace\Actions\ReinstateTeacher;
use App\Modules\Marketplace\Actions\RejectTeacherApplication;
use App\Modules\Marketplace\Actions\RequestApplicationChanges;
use App\Modules\Marketplace\Actions\SetMarketplaceParticipation;
use App\Modules\Marketplace\Actions\SuspendTeacher;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The academic team's side of the review.
 *
 * Permission names come from the Permissions constants, never string literals
 * (Constitution V). Every decision goes through the same Action the Filament
 * resource calls, so the two cannot drift.
 *
 * ⚠️ EVERY READ HERE DECLARES `withoutGlobalScope(WorkspaceScope::class)`, and the
 * reviewer's own workspace is what makes it necessary. `WorkspaceContext::id()`
 * falls back to `users.last_workspace_id` for EVERY user including a platform
 * officer — so a reviewer who also owns a workspace was served an empty queue and
 * a 404 on approve/reject, about applications that exist. A 200-shaped lie, the
 * same five-layer defect spec 024 recorded for the finance officer, reached from
 * a second module. `TeacherApplicationResource::getEloquentQuery()` — the Filament
 * twin of this screen — has carried the bypass since it was written; one answer at
 * the panel and another at the API is the two-spellings defect this repository
 * has now paid for in four modules.
 *
 * It was survivable while every application sat in ONE workspace, so the failure
 * mode was all-or-nothing. Spec 025 moves each application into its own teacher's
 * workspace permanently, which makes a cross-tenant read the only correct one this
 * screen can make.
 */
class TeacherReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::MARKETPLACE_TEACHERS_REVIEW);

        $applications = TeacherApplication::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->when(
                is_string($request->query('status')) ? $request->query('status') : null,
                fn ($query, string $status) => $query->where('status', $status),
            )
            ->with('user:id,first_name,last_name,email')
            ->orderByDesc('submitted_at')
            ->paginate(20);

        return response()->json([
            'data' => array_map(fn (TeacherApplication $application): array => [
                'uuid' => $application->uuid,
                'status' => $application->status,
                'applicant' => $application->user?->name,
                'submitted_at' => $application->submitted_at,
                'step_data' => $application->step_data ?? [],
            ], $applications->items()),
            'meta' => [
                'current_page' => $applications->currentPage(),
                'total' => $applications->total(),
                'last_page' => $applications->lastPage(),
            ],
        ]);
    }

    public function approve(Request $request, string $uuid, ApproveTeacherApplication $action): JsonResponse
    {
        $this->authorizePermission($request, Permissions::MARKETPLACE_TEACHERS_APPROVE);

        $profile = $action->handle($this->application($uuid), $this->currentUser($request));

        return response()->json([
            'approval_status' => $profile->approval_status,
            'is_publicly_listed' => $profile->is_publicly_listed,
        ]);
    }

    public function reject(Request $request, string $uuid, RejectTeacherApplication $action): JsonResponse
    {
        $this->authorizePermission($request, Permissions::MARKETPLACE_TEACHERS_APPROVE);

        $application = $action->handle(
            $this->application($uuid),
            $this->currentUser($request),
            $this->reason($request),
        );

        return response()->json(['status' => $application->status]);
    }

    public function requestChanges(Request $request, string $uuid, RequestApplicationChanges $action): JsonResponse
    {
        $this->authorizePermission($request, Permissions::MARKETPLACE_TEACHERS_APPROVE);

        $application = $action->handle(
            $this->application($uuid),
            $this->currentUser($request),
            $this->reason($request),
        );

        return response()->json(['status' => $application->status]);
    }

    public function suspend(Request $request, string $uuid, SuspendTeacher $action): JsonResponse
    {
        $this->authorizePermission($request, Permissions::MARKETPLACE_TEACHERS_SUSPEND);

        $teacher = $action->handle($this->teacher($uuid));

        return response()->json(['approval_status' => $teacher->approval_status]);
    }

    public function reinstate(Request $request, string $uuid, ReinstateTeacher $action): JsonResponse
    {
        $this->authorizePermission($request, Permissions::MARKETPLACE_TEACHERS_SUSPEND);

        $teacher = $action->handle($this->teacher($uuid));

        return response()->json([
            'approval_status' => $teacher->approval_status,
            'is_publicly_listed' => $teacher->is_publicly_listed,
        ]);
    }

    public function setParticipation(Request $request, SetMarketplaceParticipation $action): JsonResponse
    {
        $this->authorizePermission($request, Permissions::MARKETPLACE_PARTICIPATION_MANAGE);

        $validated = $request->validate(['participates' => ['required', 'boolean']]);

        $workspace = app(WorkspaceContext::class)->current();

        if ($workspace === null) {
            throw ValidationException::withMessages(['participates' => 'لا توجد مساحة عمل حالية.']);
        }

        $updated = $action->handle($workspace, (bool) $validated['participates']);

        return response()->json(['participates_in_marketplace' => $updated->participates_in_marketplace]);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($this->currentUser($request)->can($permission), 403);
    }

    private function reason(Request $request): string
    {
        // Required, not optional: every decision the applicant hears about has to
        // come with something they can act on (FR-016).
        return (string) $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ])['reason'];
    }

    private function application(string $uuid): TeacherApplication
    {
        return TeacherApplication::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function teacher(string $uuid): TeacherProfile
    {
        return TeacherProfile::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
