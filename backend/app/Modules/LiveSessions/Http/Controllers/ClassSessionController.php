<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LiveSessions\Actions\CancelClassSession;
use App\Modules\LiveSessions\Actions\GenerateSessionsFromAvailability;
use App\Modules\LiveSessions\Actions\ScheduleClassSession;
use App\Modules\LiveSessions\Actions\UpdateClassSession;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Http\Requests\GenerateSessionsRequest;
use App\Modules\LiveSessions\Http\Requests\StoreClassSessionRequest;
use App\Modules\LiveSessions\Http\Requests\UpdateClassSessionRequest;
use App\Modules\LiveSessions\Http\Resources\ClassSessionResource;
use App\Modules\LiveSessions\Models\ClassSession;
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
             | ⚠️ «المجموعة» IS THE COURSE. No group entity exists anywhere in this
             | product (`AnnouncementAudience` and `ConversationKind` both say so
             | in as many words); every schedulable session has carried a course
             | since 006, and enrolment in it is the durable set of students. A
             | second answer to «which students» would be two answers.
             */
            ->when(
                $request->query('teacher'),
                fn ($query, $uuid) => $query->whereHas('teacherProfile', fn ($teacher) => $teacher->where('uuid', $uuid)),
            )
            ->when(
                $request->query('course'),
                fn ($query, $uuid) => $query->whereHas('course', fn ($course) => $course->where('uuid', $uuid)),
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
    private function refusal(DomainException $e, string $field): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => [$field => [$e->getMessage()]],
        ], 422);
    }
}
