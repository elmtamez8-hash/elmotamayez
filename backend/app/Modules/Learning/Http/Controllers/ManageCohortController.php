<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\ArchiveCohort;
use App\Modules\Learning\Actions\CreateCohort;
use App\Modules\Learning\Actions\DecideTransferRequest;
use App\Modules\Learning\Actions\MoveMember;
use App\Modules\Learning\Actions\RemoveMember;
use App\Modules\Learning\Actions\UpdateCohort;
use App\Modules\Learning\Http\Resources\CohortMembershipEventResource;
use App\Modules\Learning\Http\Resources\CohortResource;
use App\Modules\Learning\Http\Resources\TransferRequestResource;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Support\CohortPricing;
use App\Modules\Learning\Support\CohortRefusal;
use App\Shared\Contracts\CohortPricingReasonDirectory;
use App\Shared\Contracts\CohortScheduleDirectory;
use App\Shared\Support\CohortPricingGap;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The teacher's side: the groups, who is in them, the queue and the history.
 *
 * ⚠️ ROUTE-MODEL BINDING IS SAFE HERE AND IS NOT SAFE ON THE STUDENT ROUTES,
 * for one reason: the reader IS a member of the workspace, so the context
 * resolves, `WorkspaceScope` applies, and another teacher's group is simply not
 * found. The policy is the row-level half of the same question — a list filtered
 * by a query and a record fetched by id are two different questions, and 010
 * paid for answering only one of them.
 *
 * ⚠️ AND THERE IS NO `DELETE` FOR A GROUP. Archiving is the alternative
 * (FR-035); a route that does not exist cannot be reached by a client somebody
 * writes next year.
 */
class ManageCohortController extends Controller
{
    public function __construct(
        private readonly CohortScheduleDirectory $schedule,
        /*
        | ⚠️ THE TEACHER'S SCREENS STAMP THEIR OWN ROWS (٠٣٦ · T046).
        | `CohortResource` answers `is_joinable`, which reads a stamp and RAISES
        | rather than falling back to a query — so a single-row response built
        | straight out of a write has to be stamped exactly as a list is. Four
        | responses here return one freshly-saved group.
        */
        private readonly CohortPricing $pricing,
        /*
        | ⛔ THE SECOND CONTRACT, AND IT IS ASKED FROM HERE AND NOWHERE ELSE
        | (٠٣٦ · FR-014 · T052). Whether the platform has priced a teacher's plan
        | is a commercial fact between the two of them — the student's picker and
        | the public page answer WHETHER a group is listed and never WHY.
        */
        private readonly CohortPricingReasonDirectory $reasons,
    ) {}

    public function index(Request $request, Course $course): JsonResponse
    {
        $this->authorize('viewAny', Cohort::class);

        $cohorts = Cohort::query()
            ->where('course_id', $course->getKey())
            ->orderBy('status')
            ->orderBy('name')
            ->get();

        $preview = $this->schedule->schedulePreviewFor(
            array_values($cohorts->map(fn (Cohort $cohort): int => (int) $cohort->getKey())->all()),
        );

        $this->pricing->stamp($cohorts);

        /*
        | ⛔ ASSEMBLED HERE, NEVER INSIDE `CohortResource` (عقود §٥). ONE
        | transformer serves the student's picker and both of the teacher's
        | screens, so a field added to it reaches the student too — which is the
        | measured trap this requirement names by hand.
        */
        $gaps = $this->reasons->pricingGapsFor($this->candidates($cohorts));

        return response()->json([
            'data' => $cohorts
                ->map(fn (Cohort $cohort): array => [
                    ...CohortResource::make(
                        $cohort,
                        $preview[(int) $cohort->getKey()] ?? [],
                    )->toArray($request),
                    ...$this->absenceReason($gaps[(int) $cohort->getKey()] ?? null),
                ])
                ->all(),
        ]);
    }

    /**
     * The triples the price contracts take.
     *
     * ⚠️ BUILT ONCE FOR A WHOLE LIST. Both contracts are bulk by signature: a
     * Resource runs once per row, so a per-group question inside one is an N+1
     * by construction.
     *
     * @param  iterable<int, Cohort>  $cohorts
     * @return list<array{id: int, uuid: string, course_id: int, workspace_id: int}>
     */
    private function candidates(iterable $cohorts): array
    {
        $out = [];

        foreach ($cohorts as $cohort) {
            $out[] = [
                'id' => (int) $cohort->getKey(),
                'uuid' => (string) $cohort->uuid,
                'course_id' => (int) $cohort->course_id,
                'workspace_id' => (int) $cohort->workspace_id,
            ];
        }

        return $out;
    }

    /**
     * The teacher's «why is this not listed», and what they do about it.
     *
     * ⚠️ AN EMPTY ARRAY FOR A GROUP THAT IS LISTED, never a fourth «nothing is
     * wrong» value: a case meaning «no gap» is one every caller has to remember
     * to exclude, and the first who forgets prints «لا توجد باقة» beside a group
     * that is on sale.
     *
     * @return array<string, mixed>
     */
    private function absenceReason(?CohortPricingGap $gap): array
    {
        if ($gap === null) {
            return [];
        }

        return [
            'absence_reason' => [
                'code' => $gap->value,
                'label' => $gap->label(),
                // `null` where the answer is «wait» — the one case with nothing
                // for the teacher to do, and the whole reason this field exists.
                'remedy' => $gap->remedy(),
            ],
        ];
    }

    /**
     * One group, with the course it is a run of.
     *
     * ⚠️ THE COURSE TRAVELS WITH IT, AND THAT IS THE WHOLE REASON THIS EXISTS.
     * The group's own page is reached by its uuid alone, and everything a
     * teacher does from there — the roster, the timetable, «back to the course»
     * — needs the course. Without it the page would have to fetch every course
     * and look for the one that owns this group, which is a list read to answer
     * a question one row already knows.
     */
    public function show(Request $request, Cohort $cohort): JsonResponse
    {
        $this->authorize('view', $cohort);

        $preview = $this->schedule->schedulePreviewFor([(int) $cohort->getKey()]);

        $course = Course::query()->whereKey($cohort->course_id)->first(['uuid', 'title']);

        $gaps = $this->reasons->pricingGapsFor($this->candidates([$cohort]));

        return response()->json([
            ...CohortResource::make($this->pricing->stampOne($cohort), $preview[(int) $cohort->getKey()] ?? [])->toArray($request),
            ...$this->absenceReason($gaps[(int) $cohort->getKey()] ?? null),
            'course' => $course === null ? null : [
                'uuid' => (string) $course->uuid,
                'title' => (string) $course->title,
            ],
        ]);
    }

    public function store(Request $request, Course $course, CreateCohort $action): JsonResponse
    {
        $this->authorize('create', Cohort::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        try {
            $cohort = $action->handle(
                $course,
                $this->currentUser($request),
                $validated['name'],
                $validated['description'] ?? null,
                $validated['capacity'] ?? null,
            );
        } catch (CohortRefusal $refusal) {
            return $this->refusal($refusal);
        }

        return response()->json(CohortResource::make($this->pricing->stampOne($cohort)), 201);
    }

    public function update(Request $request, Cohort $cohort, UpdateCohort $action): JsonResponse
    {
        $this->authorize('update', $cohort);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'status' => ['sometimes', 'string', 'in:open,closed'],
        ]);

        try {
            $cohort = $action->handle($cohort, $validated);
        } catch (CohortRefusal $refusal) {
            return $this->refusal($refusal);
        }

        return response()->json(CohortResource::make($this->pricing->stampOne($cohort)));
    }

    public function archive(Request $request, Cohort $cohort, ArchiveCohort $action): JsonResponse
    {
        $this->authorize('archive', $cohort);

        return response()->json(CohortResource::make($this->pricing->stampOne($action->handle($cohort, $this->currentUser($request)))));
    }

    public function members(Request $request, Cohort $cohort): JsonResponse
    {
        $this->authorize('manageMembers', $cohort);

        $members = CohortMembership::query()
            ->where('cohort_id', $cohort->getKey())
            ->whereNull('closed_at')
            /*
            | ⚠️ `first_name` AND `last_name`, NEVER `name`. `users` has no such
            | column — it is an accessor over those two — so a constrained eager
            | load naming it selects nothing and every row renders as «». That
            | shipped six times across four modules, including the screen a
            | teacher uses to decide whom to chase for money.
            */
            ->with(['student:id,uuid,first_name,last_name'])
            ->orderBy('joined_at')
            ->get();

        return response()->json([
            'data' => $members->map(fn (CohortMembership $membership): array => [
                'uuid' => $membership->student?->uuid,
                'name' => $membership->student?->name,
                'joined_at' => $membership->joined_at,
            ])->all(),
        ]);
    }

    public function addMember(Request $request, Cohort $cohort, MoveMember $action): JsonResponse
    {
        $this->authorize('manageMembers', $cohort);

        $validated = $request->validate([
            'student_uuid' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $student = User::query()->where('uuid', $validated['student_uuid'])->first();

        if ($student === null) {
            // ⚠️ THE SAME ANSWER AS "NOT YOUR STUDENT". A distinct reply for an
            // unknown uuid is an oracle telling the reader which uuids are real.
            return $this->refusal(CohortRefusal::notEnrolled());
        }

        try {
            $action->handle($cohort, $student, $this->currentUser($request), $validated['reason'] ?? null);
        } catch (CohortRefusal $refusal) {
            return $this->refusal($refusal);
        }

        return response()->json(['message' => 'تمت إضافة الطالب إلى المجموعة.'], 201);
    }

    public function removeMember(Request $request, Cohort $cohort, User $user, RemoveMember $action): JsonResponse
    {
        $this->authorize('manageMembers', $cohort);

        $membership = CohortMembership::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('student_user_id', $user->getKey())
            ->whereNull('closed_at')
            ->first();

        if ($membership === null) {
            return response()->json(['message' => 'هذا الطالب ليس في هذه المجموعة.'], 422);
        }

        $action->handle($membership, $this->currentUser($request), $request->string('reason')->value() ?: null);

        return response()->json(['message' => 'تم إخراج الطالب من المجموعة.']);
    }

    public function history(Request $request, Cohort $cohort): JsonResponse
    {
        $this->authorize('view', $cohort);

        /*
        | ⚠️ THE DISJUNCTION IS GROUPED. `AND` binds tighter than `OR`, so
        | written flat the workspace condition attaches to ONE arm and every
        | other workspace's «left this group» rows come back under it. This tree
        | has shipped that same precedence bug twice in one day, in two files.
        */
        $events = CohortMembershipEvent::query()
            ->where('workspace_id', $cohort->workspace_id)
            ->where(fn ($query) => $query
                ->where('cohort_id', $cohort->getKey())
                ->orWhere('from_cohort_id', $cohort->getKey()))
            ->with(['cohort', 'fromCohort', 'student:id,uuid,first_name,last_name', 'actor:id,uuid,first_name,last_name'])
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json(CohortMembershipEventResource::collection($events)->response()->getData(true));
    }

    public function studentHistory(Request $request, Course $course, User $student): JsonResponse
    {
        $this->authorize('viewAny', Cohort::class);

        $events = CohortMembershipEvent::query()
            ->where('course_id', $course->getKey())
            ->where('student_user_id', $student->getKey())
            ->with(['cohort', 'fromCohort', 'actor:id,uuid,first_name,last_name'])
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json(CohortMembershipEventResource::collection($events)->response()->getData(true));
    }

    public function transferRequests(Request $request, Course $course): JsonResponse
    {
        $this->authorize('viewAny', CohortTransferRequest::class);

        $requests = CohortTransferRequest::query()
            ->where('course_id', $course->getKey())
            ->where('status', CohortTransferRequest::PENDING)
            ->with(['toCohort', 'fromCohort', 'student:id,uuid,first_name,last_name'])
            ->orderBy('created_at')
            ->paginate(50);

        return response()->json(TransferRequestResource::collection($requests)->response()->getData(true));
    }

    public function decide(Request $request, CohortTransferRequest $transferRequest, DecideTransferRequest $action, bool $approve): JsonResponse
    {
        $this->authorize('decide', $transferRequest);

        $validated = $request->validate([
            'reason' => [$approve ? 'nullable' : 'required', 'string', 'max:500'],
        ]);

        try {
            $decided = $action->handle(
                $transferRequest,
                $this->currentUser($request),
                $approve,
                $validated['reason'] ?? null,
            );
        } catch (CohortRefusal $refusal) {
            return $this->refusal($refusal);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(TransferRequestResource::make($decided->load(['toCohort', 'fromCohort'])));
    }

    public function approve(Request $request, CohortTransferRequest $transferRequest, DecideTransferRequest $action): JsonResponse
    {
        return $this->decide($request, $transferRequest, $action, true);
    }

    public function reject(Request $request, CohortTransferRequest $transferRequest, DecideTransferRequest $action): JsonResponse
    {
        return $this->decide($request, $transferRequest, $action, false);
    }

    private function refusal(CohortRefusal $refusal): JsonResponse
    {
        return response()->json([
            'message' => $refusal->getMessage(),
            'code' => $refusal->refusalCode,
        ], $refusal->status);
    }
}
