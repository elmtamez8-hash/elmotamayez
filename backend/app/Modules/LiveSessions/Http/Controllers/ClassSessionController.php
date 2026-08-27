<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\AssignSessionsToCohort;
use App\Modules\LiveSessions\Actions\CancelClassSession;
use App\Modules\LiveSessions\Actions\GenerateSessionsFromAvailability;
use App\Modules\LiveSessions\Actions\ScheduleClassSession;
use App\Modules\LiveSessions\Actions\UpdateClassSession;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Http\Requests\GenerateSessionsRequest;
use App\Modules\LiveSessions\Http\Requests\StoreClassSessionRequest;
use App\Modules\LiveSessions\Http\Requests\UpdateClassSessionRequest;
use App\Modules\LiveSessions\Http\Resources\ClassSessionResource;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\CohortSessionVisibility;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\UnlockDirectory;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassSessionController extends Controller
{
    public function index(Request $request, UnlockDirectory $unlock): JsonResponse
    {
        $this->authorize('viewAny', ClassSession::class);

        /*
         | ⚠️ `< to + 1 DAY`, NEVER `<= to`, AND THE DAY IT LOSES IS THE ONE BEING
         | ASKED FOR.
         |
         | `starts_at` is a TIMESTAMP and `to` arrives as a DATE, so `<= '2026-08-26'`
         | binds midnight and silently drops every session that day — which, on a
         | screen filtered to «اليوم», is the whole answer. The same boundary has
         | already cost `FreezePeriod::covering()` and a settlement close their own
         | fixes; this is the third.
         |
         | A caller that sends a full instant is left alone: it means what it says.
         */
        $to = $request->query('to');
        $toIsBareDate = is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1;

        // Newest first for a look BACKWARDS. Ascending over a past range is the
        // «oldest fifty» defect this filter exists to end, reached from the other
        // side: page one would be the teacher's first ever week.
        $descending = $request->query('order') === 'desc';

        $sessions = ClassSession::query()
            ->when($request->query('from'), fn ($query, $from) => $query->where('starts_at', '>=', $from))
            ->when($to, fn ($query) => $toIsBareDate
                ? $query->where('starts_at', '<', CarbonImmutable::parse($to)->addDay()->toDateString())
                : $query->where('starts_at', '<=', $to))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            /*
             | ⚠️ MATCHED THROUGH THE RELATION, SO AN UNKNOWN UUID MATCHES NOTHING.
             |
             | Two things fall out of that and both are deliberate. There is no
             | `exists:` rule on either — Laravel's is a raw query with no tenant
             | condition, so a validation rule here would answer «this uuid is
             | real» to anybody who guessed one, before a policy runs. And a
             | filter whose value it cannot resolve returns an EMPTY list rather
             | than the unfiltered one: silently ignoring a filter shows a teacher
             | somebody else's calendar under their own name.
             |
             | The relation carries its own workspace scope, which is the guard —
             | a uuid from another workspace is simply not there.
             |
             */
            ->when(
                $request->query('teacher'),
                fn ($query, $uuid) => $query->whereHas('teacherProfile', fn ($teacher) => $teacher->where('uuid', $uuid)),
            )
            ->when(
                $request->query('course'),
                fn ($query, $uuid) => $query->whereHas('course', fn ($course) => $course->where('uuid', $uuid)),
            )
            /*
             | ⚠️ Q3 · FR-025ج — DISCOVERY IS FILTERED BY THE READER'S GROUP, AND
             | AN UNASSIGNED SESSION IS HIDDEN ONLY IN A COURSE THAT HAS GROUPS.
             |
             | Every session in this database predates the group and carries
             | `cohort_id = null`; a bare `whereNotNull` here would empty the
             | timetable of every course in the product overnight. So the second
             | arm keeps an unassigned session visible exactly while its course
             | runs without groups — which is FR-036, and which is why a course
             | with none passes US1 and US2 without a row changing.
             |
             | ⚠️ AND THIS IS THE DISCOVERY DOOR, NOT «حصصي». `GetStudentSchedule`
             | is built from the student's own BOOKINGS and is deliberately left
             | alone (FR-025د): a seat they hold, an attendance already recorded
             | and a recording earned by that seat are things that HAPPENED, and a
             | timetable decision may not reach back and take one away. This is a
             | judgement about what is on offer.
             |
             | The reader's own groups fail toward EMPTY: `whereIn(…, [])` matches
             | nothing, so a student in no group sees the ungrouped courses only —
             | never, by a silently ignored filter, everybody's calendar.
             */
            ->when(
                ! $this->currentUser($request)->can(Permissions::SESSIONS_MANAGE),
                fn ($query) => CohortSessionVisibility::apply($query, $this->currentUser($request)),
            )
            // Eager-loaded so a month of sessions is a fixed number of queries
            // rather than one per row (SC-011). `recordingLesson` belongs in the
            // list for the same reason the other two do: the Resource asks every
            // published session where its recording went.
            ->with(['course', 'bookings', 'recordingLesson'])
            ->orderBy('starts_at', $descending ? 'desc' : 'asc')
            ->paginate(50);

        /*
        | ⚠️ STAMPED IN BULK, NEVER ASKED PER ROW. FR-041 checks the unlock
        | condition at every access, and this list is a month of sessions — asked
        | inside the Resource it is six queries times fifty on the screen a
        | student opens first every morning. `UnlockReader` answers the whole page
        | in a fixed handful, the same shape `WithholdingReader::stamp()` has in
        | 006, and `UnlockQueryBudgetTest` fails if it stops being flat.
        */
        $unlock->stamp(collect($sessions->items()), $this->currentUser($request));

        return response()->json(ClassSessionResource::collection($sessions)->response()->getData(true));
    }

    public function store(StoreClassSessionRequest $request, ScheduleClassSession $action): JsonResponse
    {
        $this->authorize('create', ClassSession::class);

        try {
            $session = $action->handle(
                // `payload()`, not `validated()` — the request resolves each uuid
                // to its id so the DTO stays a dumb carrier of integers.
                ScheduleSessionData::fromArray($request->payload()),
                $this->currentUser($request),
            );
        } catch (DomainException $e) {
            return $this->refusal($e, 'starts_at');
        }

        return response()->json(ClassSessionResource::make($session), 201);
    }

    public function generate(GenerateSessionsRequest $request, GenerateSessionsFromAvailability $action): JsonResponse
    {
        $this->authorize('create', ClassSession::class);

        $result = $action->handle(
            $request->teacherProfile(),
            $request->course(),
            CarbonImmutable::parse((string) $request->validated('from')),
            CarbonImmutable::parse((string) $request->validated('to')),
            $this->currentUser($request),
            $request->validated('slot_uuids', []),
            (int) $request->validated('seats_total', 1),
            ClassSessionType::from((string) $request->validated('type', ClassSessionType::Individual->value)),
            $request->validated('title'),
        );

        return response()->json([
            'created' => ClassSessionResource::collection($result['created']),
            // Reported, never swallowed: a teacher must be able to see that
            // Tuesday was skipped and why.
            'skipped' => $result['skipped'],
        ], 201);
    }

    public function show(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        $session->load(['course', 'bookings', 'recordingLesson']);

        return response()->json(ClassSessionResource::make($session));
    }

    public function update(UpdateClassSessionRequest $request, ClassSession $session, UpdateClassSession $action): JsonResponse
    {
        $this->authorize('update', $session);

        try {
            $session = $action->handle($session, $request->validated());
        } catch (DomainException $e) {
            return $this->refusal($e, 'type');
        }

        return response()->json(ClassSessionResource::make($session));
    }

    public function cancel(Request $request, ClassSession $session, CancelClassSession $action): JsonResponse
    {
        $this->authorize('cancel', $session);

        try {
            $session = $action->handle($session, $request->input('reason'));
        } catch (DomainException $e) {
            return $this->refusal($e, 'status');
        }

        return response()->json(ClassSessionResource::make($session));
    }

    /**
     * A refused business rule, shaped like a validation error.
     *
     * 422 with the message under a field is what puts the Arabic text next to
     * the input that caused it — `fieldErrors()` on the frontend reads exactly
     * this shape, and anything else lands as a banner with no context.
     */
    /**
     * What Q3 is hiding from every student of this course, and how many
     * (FR-025هـ).
     *
     * ⚠️ A HIDING THE OWNER OF THE TIMETABLE DOES NOT KNOW ABOUT IS A SILENT
     * LOSS. Creating the first group of a course removes every existing session
     * from discovery at a stroke; without this list the teacher's students
     * simply stop seeing classes and nothing anywhere says why.
     *
     * ⚠️ AND THE PAST ONES ARE COUNTED SEPARATELY RATHER THAN OFFERED. FR-025و
     * refuses to assign a session that has started or ended, so listing one
     * beside an «إسناد» button is a button that answers with a refusal. The
     * count is still reported, because "eleven hidden, three of them already
     * taught" is the honest answer and "eight" is not.
     */
    public function unassignedSessions(Request $request, Course $course): JsonResponse
    {
        $this->authorize('create', ClassSession::class);

        $sessions = ClassSession::query()
            ->where('course_id', $course->getKey())
            ->whereNull('cohort_id')
            ->orderBy('starts_at')
            ->get();

        $assignable = $sessions->filter(
            fn (ClassSession $session): bool => ! $session->starts_at->isPast()
                && $session->status === ClassSessionStatus::Scheduled,
        );

        return response()->json([
            'data' => ClassSessionResource::collection($assignable->values())->toArray($request),
            'meta' => [
                'total_hidden' => $sessions->count(),
                'assignable' => $assignable->count(),
                'already_held' => $sessions->count() - $assignable->count(),
            ],
        ]);
    }

    /** One request for the whole batch — never a loop at the client. */
    public function assignSessions(Request $request, Course $course, AssignSessionsToCohort $action): JsonResponse
    {
        $this->authorize('create', ClassSession::class);

        $validated = $request->validate([
            'cohort_uuid' => ['required', 'string'],
            'session_uuids' => ['required', 'array', 'min:1', 'max:200'],
            'session_uuids.*' => ['string'],
        ]);

        try {
            $assigned = $action->handle($course, $validated['cohort_uuid'], array_values($validated['session_uuids']));
        } catch (DomainException $e) {
            return $this->refusal($e, 'session_uuids');
        }

        return response()->json(['assigned' => $assigned]);
    }

    private function refusal(DomainException $e, string $field): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => [$field => [$e->getMessage()]],
        ], 422);
    }
}
