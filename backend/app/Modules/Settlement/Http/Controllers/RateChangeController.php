<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Actions\DecideRateChange;
use App\Modules\Settlement\Actions\RequestRateChange;
use App\Modules\Settlement\Http\Controllers\Concerns\ResolvesOwnTeacher;
use App\Modules\Settlement\Http\Requests\DecideRateChangeRequest;
use App\Modules\Settlement\Http\Requests\StoreRateChangeRequest;
use App\Modules\Settlement\Http\Resources\RateChangeRequestResource;
use App\Modules\Settlement\Http\Resources\SettlementRateResource;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementRate;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RateChangeController extends Controller
{
    use ResolvesOwnTeacher;

    /** The teacher's own requests, newest first. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RateChangeRequest::class);

        $requests = RateChangeRequest::query()
            // Their own price, not their workspace's. See ResolvesOwnTeacher.
            ->where('teacher_profile_id', $this->ownTeacherProfileId($request))
            ->orderByDesc('requested_at')
            ->paginate(50);

        return response()->json(RateChangeRequestResource::collection($requests)->response()->getData(true));
    }

    /** The teacher's rates currently on file. */
    public function rates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RateChangeRequest::class);

        $rates = SettlementRate::query()
            ->where('teacher_profile_id', $this->ownTeacherProfileId($request))
            ->orderByDesc('effective_from')
            ->get();

        return response()->json(SettlementRateResource::collection($rates));
    }

    public function store(StoreRateChangeRequest $request, RequestRateChange $action): JsonResponse
    {
        $this->authorize('create', RateChangeRequest::class);

        $user = $this->currentUser($request);

        $teacher = TeacherProfile::query()
            ->where('user_id', $user->getKey())
            ->firstOrFail();

        try {
            $created = $action->handle(
                $teacher,
                ClassSessionType::from((string) $request->validated('session_type')),
                (int) $request->validated('requested_amount_minor'),
                $user,
                $request->validated('subject_id') === null ? null : (int) $request->validated('subject_id'),
                $request->validated('grade_level'),
            );
        } catch (DomainException $e) {
            return $this->refusal($e, 'requested_amount_minor');
        }

        return response()->json(RateChangeRequestResource::make($created), 201);
    }

    public function approve(Request $request, RateChangeRequest $rateRequest, DecideRateChange $action): JsonResponse
    {
        $this->authorize('decide', $rateRequest);

        try {
            $rate = $action->approve($rateRequest, $this->currentUser($request));
        } catch (DomainException $e) {
            return $this->refusal($e, 'status');
        }

        return response()->json(SettlementRateResource::make($rate), 201);
    }

    public function reject(DecideRateChangeRequest $request, RateChangeRequest $rateRequest, DecideRateChange $action): JsonResponse
    {
        $this->authorize('decide', $rateRequest);

        try {
            $decided = $action->reject($rateRequest, $this->currentUser($request), (string) $request->validated('reason'));
        } catch (DomainException $e) {
            return $this->refusal($e, 'reason');
        }

        return response()->json(RateChangeRequestResource::make($decided));
    }

    /**
     * A refused business rule, shaped like a validation error.
     *
     * 422 with the message under a field is what puts the Arabic text beside the
     * input that caused it — `fieldErrors()` on the frontend reads exactly this
     * shape, and anything else lands as a banner with no context.
     */
    private function refusal(DomainException $e, string $field): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => [$field => [$e->getMessage()]],
        ], 422);
    }
}
