<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Assessments\Actions\GrantUnlockExemption;
use App\Modules\Assessments\Models\UnlockExemption;
use App\Modules\Assessments\Models\UnlockRule;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Support\WorkspaceRules;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What earns the next session, set once for the workspace and overridden per
 * course (FR-037).
 *
 * ⚠️ THE LIST ANSWERS WITH THE DEFAULT AND EVERY OVERRIDE TOGETHER, because the
 * screen has to show a teacher WHICH default a course override is replacing.
 * Precedence is invisible otherwise: they set 50٪ in one place and 80٪ in
 * another and cannot see, from either screen, which one a given student met.
 */
class UnlockRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        abort_unless($user->can(Permissions::UNLOCK_RULES_MANAGE), 403);

        /*
        | ⛔ A CONFINED ASSISTANT READS THE OVERRIDES OF THEIR OWN COURSES (spec
        | 010 · FR-005) — and the default beside them, because the screen exists
        | to show which default an override replaces. `null` is «not confined»;
        | the directory never answers `[]`.
        */
        $workspaceId = app(WorkspaceContext::class)->id();
        $scoped = $workspaceId === null
            ? null
            : app(AssistantScopeDirectory::class)->scopedCourseIdsFor($user, $workspaceId);

        $rules = UnlockRule::query()
            ->when($scoped !== null, fn ($query) => $query->whereIn(
                'course_id',
                [...($scoped ?? []), UnlockRule::DEFAULT_SCOPE],
            ))
            ->orderBy('course_id')
            ->get();

        // ⚠️ ONE LOOKUP FOR THE WHOLE LIST. Resolving the course inside the
        // presenter is two queries per row — small here and the same shape that
        // cost `ClassSessionResource` a fix, so it is not written that way.
        $courses = DB::table('courses')
            ->whereIn('id', $rules->pluck('course_id')->all())
            ->get(['id', 'uuid', 'title'])
            ->keyBy('id');

        return response()->json([
            'data' => $rules->map(fn (UnlockRule $rule): array => $this->present($rule, $courses))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->currentUser($request)->can(Permissions::UNLOCK_RULES_MANAGE), 403);

        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return response()->json(['message' => 'تعذّر تحديد مكان عملك. أعد تحميل الصفحة.'], 422);
        }

        $validated = $request->validate([
            /*
            | ⚠️ A UUID, NEVER THE AUTOINCREMENT ID. Every route in this product
            | binds by uuid and no payload has ever carried a sequential key —
            | and the screen could not supply one anyway, because the course list
            | it reads exposes uuids alone.
            |
            | `WorkspaceRules::exists`, never a bare `exists:courses,uuid`: that
            | rule is a raw query with no global scope, so it would confirm that
            | another workspace's course exists.
            */
            'course_uuid' => ['nullable', 'uuid', WorkspaceRules::exists('courses', 'uuid')],
            'requires_attendance' => ['required', 'boolean'],
            'requires_assignment' => ['required', 'boolean'],
            'min_score_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $courseId = ($validated['course_uuid'] ?? null) === null
            ? UnlockRule::DEFAULT_SCOPE
            : (int) DB::table('courses')
                ->where('workspace_id', $workspaceId)
                ->where('uuid', $validated['course_uuid'])
                ->value('id');

        /*
        | ⚠️ A FAILED RESOLVE MUST NOT BECOME THE DEFAULT. `(int) null` is 0, and
        | 0 is the sentinel for "this workspace's default rule" — so a uuid that
        | validated against one context and resolved against another would
        | silently OVERWRITE the rule governing every course, while the response
        | said the course-specific rule had been saved. Loud, not silent.
        */
        abort_if($courseId === UnlockRule::DEFAULT_SCOPE && ($validated['course_uuid'] ?? null) !== null, 404);

        /*
        | ⛔ THE ASSISTANT SCOPE, BESIDE THE PERMISSION (spec 010 · FR-005). A
        | course rule is asked about its course; the DEFAULT governs every course
        | in the workspace, so it is asked as «no course» — which a confined
        | assistant is refused and everybody else passes.
        */
        $this->abortOutsideAssistantScope(
            $request,
            $workspaceId,
            $courseId === UnlockRule::DEFAULT_SCOPE ? null : $courseId,
            'هذا الكورس خارج نطاق عملك.',
        );

        $rule = UnlockRule::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                // ⚠️ ZERO, NEVER NULL. `unique(workspace_id, course_id)` would not
                // bite on a nullable column — NULL never equals NULL — and a
                // workspace would end up with two defaults and no way to choose.
                'course_id' => $courseId,
            ],
            [
                'requires_attendance' => (bool) $validated['requires_attendance'],
                'requires_assignment' => (bool) $validated['requires_assignment'],
                'min_score_pct' => (float) ($validated['min_score_pct'] ?? 0),
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'updated_by' => $this->currentUser($request)->getKey(),
            ],
        );

        $courses = DB::table('courses')
            ->where('id', $rule->course_id)
            ->get(['id', 'uuid', 'title'])
            ->keyBy('id');

        return response()->json(['data' => $this->present($rule, $courses)], 201);
    }

    /**
     * Who has been let past on one session, and why (FR-040).
     *
     * ⚠️ THE SAME DOOR AS THE WRITE: `unlock.rules.manage` and the same
     * workspace-bound session lookup `exempt()` uses, so another workspace's
     * session answers 404 here exactly as it does there.
     *
     * ⚠️ `student` IS EAGER-LOADED WHOLE. `name` is an accessor over
     * `first_name` and `last_name`; a column-restricted load that names it
     * renders every row blank with a 200 and no error anywhere.
     */
    public function exemptions(Request $request, string $sessionUuid): JsonResponse
    {
        abort_unless($this->currentUser($request)->can(Permissions::UNLOCK_RULES_MANAGE), 403);

        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return response()->json(['message' => 'تعذّر تحديد مكان عملك. أعد تحميل الصفحة.'], 422);
        }

        $sessionId = $this->sessionInScope($request, $workspaceId, $sessionUuid);

        $rows = UnlockExemption::query()
            ->where('workspace_id', $workspaceId)
            ->where('class_session_id', $sessionId)
            ->with('student')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (UnlockExemption $row): array => [
                'uuid' => $row->uuid,
                'student' => $row->student === null ? null : [
                    'uuid' => $row->student->uuid,
                    'name' => $row->student->name,
                ],
                'reason' => $row->reason,
                // `updated_at`, not `created_at`: a second grant for the same
                // student rewrites the reason in place (`updateOrCreate`).
                'granted_at' => $row->updated_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Let one student past on one session (FR-040).
     *
     * ⚠️ 404 FOR BOTH FAILURES — a uuid that names nobody and a student this
     * workspace does not teach answer identically, because a distinct reply for
     * the second confirms the first names a real account.
     */
    public function exempt(Request $request, string $sessionUuid, GrantUnlockExemption $action): JsonResponse
    {
        /*
        | One permission for both acts, and deliberately so. US5 split grading
        | from revising because the second OVERRULES the first — a decision the
        | teacher keeps for themselves. These two do not overrule each other:
        | setting the rule and letting one student past it are the same person's
        | authority over the same condition, exercised at two scales.
        */
        abort_unless($this->currentUser($request)->can(Permissions::UNLOCK_RULES_MANAGE), 403);

        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return response()->json(['message' => 'تعذّر تحديد مكان عملك. أعد تحميل الصفحة.'], 422);
        }

        $validated = $request->validate([
            'student_uuid' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $sessionId = $this->sessionInScope($request, $workspaceId, $sessionUuid);

        $student = User::query()->where('uuid', $validated['student_uuid'])->first();

        abort_if($student === null, 404);

        try {
            $exemption = $action->handle(
                $workspaceId,
                $sessionId,
                $this->currentUser($request),
                $student,
                $validated['reason'],
            );
        } catch (DomainException) {
            abort(404);
        }

        return response()->json(['data' => ['uuid' => $exemption->uuid]], 201);
    }

    /**
     * The session's id — 404 when this workspace has no such session, 403 when
     * it lies outside a confined assistant's courses (spec 010 · FR-005).
     *
     * Read without importing a LiveSessions model: Assessments owns the
     * exemption, not the session, and Constitution III forbids reaching into
     * another module's models.
     *
     * ⚠️ A SESSION WITH NO COURSE IS REFUSED TO A CONFINED ASSISTANT — the answer
     * `ClassSessionPolicy` gives the same row. A null course is never «inside».
     */
    private function sessionInScope(Request $request, int $workspaceId, string $sessionUuid): int
    {
        $session = DB::table('class_sessions')
            ->where('workspace_id', $workspaceId)
            ->where('uuid', $sessionUuid)
            ->first(['id', 'course_id']);

        abort_if($session === null, 404);

        $this->abortOutsideAssistantScope(
            $request,
            $workspaceId,
            $session->course_id === null ? null : (int) $session->course_id,
            'هذه الحصّة خارج نطاق عملك.',
        );

        return (int) $session->id;
    }

    /** Asked BESIDE `unlock.rules.manage`, never instead of it. */
    private function abortOutsideAssistantScope(Request $request, int $workspaceId, ?int $courseId, string $message): void
    {
        abort_unless(
            app(AssistantScopeDirectory::class)->mayActOnCourse($this->currentUser($request), $workspaceId, $courseId),
            403,
            $message,
        );
    }

    /**
     * @param  Collection<int, \stdClass>  $courses
     * @return array<string, mixed>
     */
    private function present(UnlockRule $rule, $courses): array
    {
        $course = $courses->get((int) $rule->course_id);

        return [
            'uuid' => $rule->uuid,
            // The course by uuid, on the way out as well as in. `course_id` is
            // deliberately absent from the payload: it is the key the sentinel
            // trick needs internally, and no client has any use for it.
            'course_uuid' => $course?->uuid,
            'course_title' => $course?->title,
            'is_default' => $rule->isDefault(),
            'requires_attendance' => $rule->requires_attendance,
            'requires_assignment' => $rule->requires_assignment,
            'min_score_pct' => (float) $rule->min_score_pct,
            'is_active' => $rule->is_active,
        ];
    }
}
