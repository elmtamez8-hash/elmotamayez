<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Assessments\Actions\GrantAccommodation;
use App\Modules\Assessments\Http\Requests\GrantAccommodationRequest;
use App\Modules\Assessments\Models\Accommodation;
use App\Shared\Support\WorkspaceContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Extra time and extra days, arranged for one student (FR-053 · FR-055).
 *
 * ⚠️ THERE IS NO STUDENT-FACING ROUTE HERE, AND ITS ABSENCE IS FR-056. The
 * holder sees a later deadline and a longer duration on their own work; a list
 * endpoint would be the one place a classmate could learn an arrangement exists.
 */
class AccommodationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Accommodation::class);

        $rows = Accommodation::query()
            ->active()
            ->with('student:id,uuid,first_name,last_name')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Accommodation $row): array => [
                'uuid' => $row->uuid,
                'student' => $row->student === null ? null : ['uuid' => $row->student->uuid, 'name' => $row->student->name],
                'extra_time_pct' => $row->extra_time_pct,
                'extended_days' => $row->extended_days,
                'reason' => $row->reason,
                'granted_at' => $row->created_at,
            ])->all(),
        ]);
    }

    public function store(GrantAccommodationRequest $request, GrantAccommodation $action): JsonResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return response()->json(['message' => 'تعذّر تحديد مكان عملك. أعد تحميل الصفحة.'], 422);
        }

        $student = User::query()->where('uuid', $request->string('student_uuid')->toString())->first();

        // ⚠️ 404, and the same 404 the Action's refusal produces below. Telling
        // "no such person" apart from "not your student" confirms the uuid names
        // a real account, which is the probe the guard exists to close.
        abort_if($student === null, 404);

        try {
            $accommodation = $action->handle(
                $workspaceId,
                $this->currentUser($request),
                $student,
                (int) $request->integer('extra_time_pct'),
                (int) $request->integer('extended_days'),
                $request->string('reason')->toString(),
            );
        } catch (DomainException $exception) {
            abort(404);
        }

        return response()->json([
            'data' => [
                'uuid' => $accommodation->uuid,
                'extra_time_pct' => $accommodation->extra_time_pct,
                'extended_days' => $accommodation->extended_days,
            ],
        ], 201);
    }

    public function destroy(Request $request, Accommodation $accommodation, GrantAccommodation $action): JsonResponse
    {
        $this->authorize('manage', $accommodation);

        $action->revoke($accommodation, $this->currentUser($request));

        return response()->json(['data' => ['uuid' => $accommodation->uuid, 'revoked' => true]]);
    }
}
