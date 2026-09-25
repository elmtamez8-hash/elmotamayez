<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
use App\Modules\LiveSessions\Actions\DeleteFreezePeriod;
use App\Modules\LiveSessions\Http\Requests\StoreFreezePeriodRequest;
use App\Modules\LiveSessions\Http\Resources\ClassSessionResource;
use App\Modules\LiveSessions\Http\Resources\FreezePeriodResource;
use App\Modules\LiveSessions\Models\FreezePeriod;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FreezePeriodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FreezePeriod::class);

        $periods = FreezePeriod::query()
            ->with(['student', 'creator'])
            ->orderByDesc('starts_on')
            ->get();

        return response()->json(['data' => FreezePeriodResource::collection($periods)]);
    }

    /**
     * Creating a period is the one write that can take booked hours away from
     * students, so the response says what it took: the suspended sessions and
     * how many seat holders were told. A teacher must see the cost of the button
     * they just pressed.
     */
    public function store(StoreFreezePeriodRequest $request, CreateFreezePeriod $action): JsonResponse
    {
        $this->authorize('create', FreezePeriod::class);

        $studentUuid = $request->validated('student_uuid');

        $student = $studentUuid === null
            ? null
            : User::query()->where('uuid', $studentUuid)->first();

        try {
            $result = $action->handle(
                $this->currentUser($request),
                CarbonImmutable::parse((string) $request->validated('starts_on')),
                CarbonImmutable::parse((string) $request->validated('ends_on')),
                $student,
                $request->validated('reason'),
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => FreezePeriodResource::make($result['period']->load(['student', 'creator'])),
            /*
            | ⛔ ٠٣٥ — DELIBERATELY NOT STAMPED, and that is the answer rather
            | than an omission. `content_locked` is a fact about a STUDENT, and
            | the caller here is the teacher declaring the freeze: stamping with
            | them would answer false for every row, because a teacher holds no
            | seat in their own workspace and is charged nothing. That is the
            | `/eligibility` defect exactly — it told the host of a lesson «لست
            | مسجَّلاً عند هذا المدرّس».
            |
            | Null reads as «not asked», which is what this payload means. A
            | screen that needs the answer asks the student's own endpoint for it.
            */
            'suspended' => ClassSessionResource::collection($result['suspended']),
            // A freeze on one student takes their seat out of a group session
            // and leaves the lesson running for everyone else — still a cost the
            // teacher should see, so it is listed beside the suspensions.
            'released' => ClassSessionResource::collection($result['released']),
            'notified' => $result['notified'],
        ], 201);
    }

    /**
     * Lifting a freeze hands its still-future sessions back to the calendar.
     *
     * The SESSIONS come back, never their seats: those were released and every
     * holder was told the hour was off, so seating them again unasked would put
     * students in a room nobody told them about. A restored session is bookable
     * again and whoever still wants it books it. The ones that could not come
     * back — started already, still under another freeze, or clashing with
     * something the teacher scheduled since — are listed, so the teacher sees
     * what the lift did and did not undo (see DeleteFreezePeriod).
     */
    public function destroy(Request $request, FreezePeriod $period, DeleteFreezePeriod $action): JsonResponse
    {
        $this->authorize('delete', $period);

        $result = $action->handle($period);

        return response()->json([
            'deleted' => true,
            'restored' => ClassSessionResource::collection($result['restored']),
            'kept_suspended' => ClassSessionResource::collection($result['kept']),
        ]);
    }
}
