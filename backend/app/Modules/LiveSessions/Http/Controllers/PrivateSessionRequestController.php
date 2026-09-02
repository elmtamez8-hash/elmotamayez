<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\DecidePrivateSessionRequest;
use App\Modules\LiveSessions\Actions\RequestPrivateSession;
use App\Modules\LiveSessions\Actions\WithdrawPrivateSessionRequest;
use App\Modules\LiveSessions\Exceptions\PrivateSessionConflictException;
use App\Modules\LiveSessions\Http\Requests\RequestPrivateSessionRequest;
use App\Modules\LiveSessions\Http\Resources\PrivateSessionRequestResource;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The student's asks and the teacher's queue.
 *
 * ⚠️ NO IMPLICIT MODEL BINDING ON A REQUEST, EVER. `WorkspaceScope` adds no
 * condition when the context is null — which it always is for a student, who is
 * a member of no workspace — so `{privateSessionRequest}` in a signature would
 * resolve ANY uuid on the platform and hand it to a policy that must then be the
 * only thing standing between one student and another's calendar. The row is
 * fetched here and the policy asked immediately after, so both halves are
 * visible in one place.
 *
 * ⚠️ AND BOTH LISTS FILTER EXPLICITLY. The student's by their own id, the
 * teacher's by the workspace they are currently in — never left to the scope,
 * and never taken from the query string.
 */
class PrivateSessionRequestController extends Controller
{
    /** The student's own asks, live and settled. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = PrivateSessionRequest::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $this->currentUser($request)->getKey())
            ->with(['course:id,uuid,title', 'classSession:id,uuid'])
            ->latest('id')
            ->paginate(20);

        return PrivateSessionRequestResource::collection($requests);
    }

    public function store(
        RequestPrivateSessionRequest $request,
        Course $course,
        RequestPrivateSession $action,
    ): JsonResponse {
        try {
            $created = $action->handle(
                $course,
                $this->currentUser($request),
                CarbonImmutable::parse((string) $request->validated('starts_at'))->utc(),
            );
        } catch (DomainException $e) {
            // 422: every refusal here is about the submission — not enrolled,
            // outside the declared hours, a duplicate moment, the ceiling. The
            // sentence is the answer, so the form can put it under the field
            // rather than say «تعذّر».
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            PrivateSessionRequestResource::make($created->load('course:id,uuid,title')),
            201,
        );
    }

    public function destroy(
        Request $request,
        string $uuid,
        WithdrawPrivateSessionRequest $action,
    ): JsonResponse {
        $found = $this->resolve($uuid);

        $this->authorize('withdraw', $found);

        try {
            $found = $action->handle($found, $this->currentUser($request));
        } catch (PrivateSessionConflictException $e) {
            // Caught ABOVE the general arm — it extends DomainException, so the
            // order is what makes the distinct answer reachable at all.
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(PrivateSessionRequestResource::make($found));
    }

    /** The teacher's queue (FR-018). */
    public function queue(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PrivateSessionRequest::class);

        $requests = PrivateSessionRequest::query()
            /*
            | ⚠️ THE WORKSPACE IS NAMED, NOT LEFT TO THE SCOPE — AND IT IS NEVER
            | TAKEN FROM THE REQUEST. `WorkspaceScope` would answer correctly
            | here, and relying on it makes the filter invisible to whoever next
            | edits this query; a `teacher_profile_id` from the client would let
            | anyone holding the permission read another teacher's queue by
            | typing a number.
            */
            ->withoutWorkspaceScope()
            ->where('workspace_id', app(WorkspaceContext::class)->id())
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', (string) $request->string('status')),
                fn ($query) => $query->pending(),
            )
            ->with([
                'course:id,uuid,title',
                // ⚠️ `first_name` AND `last_name`, NEVER `name`: `users` has no
                // such column — it is an accessor — and a constrained eager load
                // naming it renders every row's byline as an empty string, with
                // no error and a 200.
                'student:id,uuid,first_name,last_name',
            ])
            ->orderBy('starts_at')
            ->paginate(20);

        return PrivateSessionRequestResource::collection($requests);
    }

    public function decide(
        Request $request,
        string $uuid,
        DecidePrivateSessionRequest $action,
    ): JsonResponse {
        $found = $this->resolve($uuid);

        $this->authorize('decide', $found);

        $validated = $request->validate([
            'accept' => ['required', 'boolean'],
            'decision_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $found = $action->handle(
                $found,
                $this->currentUser($request),
                (bool) $validated['accept'],
                $validated['decision_reason'] ?? null,
            );
        } catch (PrivateSessionConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (DomainException $e) {
            // 422: «no credit left», «you have another lesson then», «a reason is
            // required». Each names something the teacher can act on.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            PrivateSessionRequestResource::make($found->load(['course:id,uuid,title', 'classSession:id,uuid'])),
        );
    }

    private function resolve(string $uuid): PrivateSessionRequest
    {
        $found = PrivateSessionRequest::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $uuid)
            ->first();

        abort_if($found === null, 404);

        return $found;
    }
}
