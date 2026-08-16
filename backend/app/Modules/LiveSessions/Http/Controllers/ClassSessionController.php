<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Course;
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
use App\Modules\Marketplace\Models\TeacherProfile;
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

        $sessions = ClassSession::query()
            ->when($request->query('from'), fn ($query, $from) => $query->where('starts_at', '>=', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->where('starts_at', '<=', $to))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            // Eager-loaded so a month of sessions is a fixed number of queries
            // rather than one per row (SC-011). `recordingLesson` belongs in the
            // list for the same reason the other two do: the Resource asks every
            // published session where its recording went.
            ->with(['course', 'bookings', 'recordingLesson'])
            ->orderBy('starts_at')
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
                ScheduleSessionData::fromArray($request->validated()),
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

        $teacher = TeacherProfile::query()
            ->where('id', $request->validated('teacher_profile_id'))
            ->firstOrFail();

        $course = Course::query()
            ->where('id', $request->validated('course_id'))
            ->firstOrFail();

        $result = $action->handle(
            $teacher,
            $course,
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
