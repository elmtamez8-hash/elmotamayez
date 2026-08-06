<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
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
            'suspended' => ClassSessionResource::collection($result['suspended']),
            'notified' => $result['notified'],
        ], 201);
    }

    /**
     * Lifting a freeze removes the period and nothing else.
     *
     * The sessions it suspended stay suspended: they were announced as not
     * happening and their seats went back, so quietly reviving them would put
     * students in a room nobody told them about again (FR-043 is about counters,
     * which never moved in the first place).
     */
    public function destroy(Request $request, FreezePeriod $period): JsonResponse
    {
        $this->authorize('delete', $period);

        $period->delete();

        return response()->json(['deleted' => true]);
    }
}
