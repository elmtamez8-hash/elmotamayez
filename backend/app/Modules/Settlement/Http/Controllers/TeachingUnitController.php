<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settlement\Actions\ReverseTeachingUnit;
use App\Modules\Settlement\Http\Requests\ReverseUnitRequest;
use App\Modules\Settlement\Http\Resources\TeachingUnitResource;
use App\Modules\Settlement\Models\TeachingUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeachingUnitController extends Controller
{
    /** The teacher's own units, filtered by period and status. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TeachingUnit::class);

        $units = TeachingUnit::query()
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('delivered_at')
            ->paginate(50);

        return response()->json(TeachingUnitResource::collection($units)->response()->getData(true));
    }

    /**
     * The correction, and the only path to one.
     *
     * An explicit act with an author and a reason, because that is what FR-006
     * asks a reversal to carry and neither is something an event listener has.
     * Attendance edits deliberately do NOT reach here (Q7): the unit is the
     * frozen seat, and 005 fails the build over any financial effect of a mark.
     */
    public function reverse(ReverseUnitRequest $request, TeachingUnit $unit, ReverseTeachingUnit $action): JsonResponse
    {
        $this->authorize('reverse', $unit);

        $reversal = $action->handle(
            $unit,
            (string) $request->validated('reason'),
            $this->currentUser($request),
        );

        if ($reversal === null) {
            return response()->json([
                'message' => 'هذه الوحدة مصحَّحة سلفاً.',
                'errors' => ['reason' => ['هذه الوحدة مصحَّحة سلفاً.']],
            ], 422);
        }

        return response()->json(TeachingUnitResource::make($reversal), 201);
    }
}
