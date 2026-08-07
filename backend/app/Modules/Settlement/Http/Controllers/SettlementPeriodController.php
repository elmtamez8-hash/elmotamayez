<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settlement\Actions\CloseSettlementPeriod;
use App\Modules\Settlement\Actions\RecordTeacherPayout;
use App\Modules\Settlement\Http\Controllers\Concerns\ResolvesOwnTeacher;
use App\Modules\Settlement\Http\Requests\RecordPayoutRequest;
use App\Modules\Settlement\Http\Resources\SettlementPeriodResource;
use App\Modules\Settlement\Http\Resources\TeacherPayoutResource;
use App\Modules\Settlement\Models\SettlementPeriod;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two irreversible acts, behind two different permissions.
 *
 * Closing and paying are separate on purpose: they are the only things in this
 * module that cannot be undone, and one "manage settlement" permission would
 * hand both to whoever needed either.
 */
class SettlementPeriodController extends Controller
{
    use ResolvesOwnTeacher;

    /** The teacher's own closed periods, newest first. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SettlementPeriod::class);

        $periods = SettlementPeriod::query()
            ->where('teacher_profile_id', $this->ownTeacherProfileId($request))
            ->orderByDesc('starts_on')
            ->paginate(24);

        return response()->json(SettlementPeriodResource::collection($periods)->response()->getData(true));
    }

    public function close(Request $request, SettlementPeriod $period, CloseSettlementPeriod $action): JsonResponse
    {
        $this->authorize('close', $period);

        $closed = $action->handle($period, $this->currentUser($request));

        if ($closed === null) {
            return $this->refusal('هذه الفترة مغلقة سلفاً.', 'status');
        }

        return response()->json(SettlementPeriodResource::make($closed));
    }

    public function pay(RecordPayoutRequest $request, SettlementPeriod $period, RecordTeacherPayout $action): JsonResponse
    {
        $this->authorize('pay', $period);

        try {
            $payout = $action->handle(
                $period,
                $this->currentUser($request),
                $request->validated('reference'),
                $request->validated('method'),
            );
        } catch (DomainException $e) {
            return $this->refusal($e->getMessage(), 'reference');
        }

        if ($payout === null) {
            return $this->refusal('صُرفت هذه الفترة سلفاً.', 'reference');
        }

        return response()->json(TeacherPayoutResource::make($payout), 201);
    }

    /**
     * A refused business rule, shaped like a validation error.
     *
     * 422 with the message under a field is what puts the Arabic text beside the
     * input that caused it — `fieldErrors()` on the frontend reads exactly this
     * shape, and anything else lands as a banner with no context.
     */
    private function refusal(string $message, string $field): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }
}
