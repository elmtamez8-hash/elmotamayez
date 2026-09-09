<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LiveSessions\Actions\DecideSessionRescheduleRequest;
use App\Modules\LiveSessions\Actions\RequestSessionReschedule;
use App\Modules\LiveSessions\Http\Resources\SessionRescheduleRequestResource;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
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
 * a member of no workspace — so `{sessionRescheduleRequest}` in a signature would
 * resolve ANY uuid on the platform. The row is fetched here and the policy asked
 * in the next line, so both halves are visible in one place.
 *
 * ⚠️ AND THE SESSION IS NOT BOUND EITHER, for exactly the same reason: the
 * student asking about it is the one the scope is inert for. It is resolved
 * explicitly and the seat check inside the Action is the guard.
 *
 * ⚠️ BOTH LISTS FILTER EXPLICITLY — the student's by their own id, the teacher's
 * by the workspace they are currently in, never taken from the query string.
 */
class SessionRescheduleRequestController extends Controller
{
    /** The student's own asks, live and settled. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = SessionRescheduleRequest::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $this->currentUser($request)->getKey())
            ->with(['classSession:id,uuid,title'])
            ->latest('id')
            ->paginate(20);

        return SessionRescheduleRequestResource::collection($requests);
    }

    public function store(
        Request $request,
        string $uuid,
        RequestSessionReschedule $action,
    ): JsonResponse {
        $validated = $request->validate([
            'to_starts_at' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $session = ClassSession::query()->withoutWorkspaceScope()->where('uuid', $uuid)->first();

        abort_if($session === null, 404);

        try {
            $created = $action->handle(
                $session,
                $this->currentUser($request),
                CarbonImmutable::parse((string) $validated['to_starts_at'])->utc(),
                $validated['reason'] ?? null,
            );
        } catch (DomainException $e) {
            // 422: every refusal here is about the submission — no seat, a past
            // hour, a request already waiting. The sentence is the answer, so
            // the form can print it rather than say «تعذّر».
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            SessionRescheduleRequestResource::make(
                $created->load('classSession:id,uuid,title'),
            ),
            201,
        );
    }

    /** The teacher's queue. */
    public function queue(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SessionRescheduleRequest::class);

        $requests = SessionRescheduleRequest::query()
            /*
            | ⚠️ THE WORKSPACE IS NAMED, NOT LEFT TO THE SCOPE, and never taken
            | from the client. The scope would answer correctly here; relying on
            | it makes the filter invisible to whoever next edits this query.
            */
            ->withoutWorkspaceScope()
            ->where('workspace_id', app(WorkspaceContext::class)->id())
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', (string) $request->string('status')),
                fn ($query) => $query->pending(),
            )
            ->with([
                'classSession:id,uuid,title',
                // ⚠️ `first_name` AND `last_name`, NEVER `name`: `users` has no
                // such column — it is an accessor — and a constrained eager load
                // naming it renders every byline as an empty string, with a 200.
                'student:id,uuid,first_name,last_name',
            ])
            ->orderBy('to_starts_at')
            ->paginate(20);

        return SessionRescheduleRequestResource::collection($requests);
    }

    public function decide(
        Request $request,
        string $uuid,
        DecideSessionRescheduleRequest $action,
    ): JsonResponse {
        $found = SessionRescheduleRequest::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $uuid)
            ->first();

        abort_if($found === null, 404);

        $this->authorize('decide', $found);

        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'decision_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $found = $action->handle(
                $found,
                $this->currentUser($request),
                (bool) $validated['approve'],
                $validated['decision_reason'] ?? null,
            );
        } catch (DomainException $e) {
            // 422: «الموعد الجديد يصطدم بحصة أخرى», «سبب الرفض مطلوب», «تم البتّ
            // بالفعل». Each names something the teacher can act on — and the
            // clash sentence is the one the whole feature turns on.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            SessionRescheduleRequestResource::make(
                $found->load(['classSession:id,uuid,title', 'student:id,uuid,first_name,last_name']),
            ),
        );
    }
}
