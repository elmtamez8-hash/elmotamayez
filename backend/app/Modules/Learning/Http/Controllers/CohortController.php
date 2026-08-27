<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\RequestTransfer;
use App\Modules\Learning\Actions\WithdrawTransferRequest;
use App\Modules\Learning\Http\Resources\CohortResource;
use App\Modules\Learning\Http\Resources\TransferRequestResource;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Support\CohortRefusal;
use App\Shared\Contracts\CohortScheduleDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's side of the group: what is on offer, and getting into one.
 *
 * ⚠️ NOT ONE ROUTE HERE RELIES ON THE WORKSPACE SCOPE. A student is a member of
 * no workspace, so `WorkspaceContext::id()` is null and `WorkspaceScope` adds no
 * condition at all — an implicit `{cohort}` binding resolves ANY teacher's group
 * on the platform. The guard on every one of these is the enrolment, asked
 * explicitly, and it is stricter than a workspace filter would have been.
 */
class CohortController extends Controller
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly CohortScheduleDirectory $schedule,
    ) {}

    /** The picker: what this course offers, and where the reader already stands. */
    public function index(Request $request, Course $course): JsonResponse
    {
        $user = $this->currentUser($request);
        $courseId = (int) $course->getKey();

        if (! $this->enrollments->hasActiveEnrollment($user, $courseId)) {
            abort(403, 'لست مسجَّلاً في هذا الكورس.');
        }

        /*
        | ⚠️ ARCHIVED GROUPS ARE OUT OF THE PICKER AND `closed` ONES ARE IN IT.
        | A closed group is a run the reader can see is happening and cannot
        | join; hiding it makes «لماذا لا أرى مجموعتي؟» unanswerable for a
        | student whose classmates are in it. An archived one is over.
        */
        $cohorts = Cohort::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $courseId)
            ->where('status', '!=', Cohort::ARCHIVED)
            ->orderBy('name')
            ->get();

        // Asked ONCE for the whole list. Inside the Resource it would be one
        // query per group, on the screen that exists to be compared across.
        $preview = $this->schedule->schedulePreviewFor(
            array_values($cohorts->map(fn (Cohort $cohort): int => (int) $cohort->getKey())->all()),
        );

        $membership = CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('course_id', $courseId)
            ->whereNull('closed_at')
            ->with('cohort')
            ->first();

        $pending = CohortTransferRequest::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('course_id', $courseId)
            ->where('status', CohortTransferRequest::PENDING)
            ->with(['toCohort', 'fromCohort'])
            ->first();

        return response()->json([
            'membership' => $membership === null ? null : [
                'cohort_uuid' => $membership->cohort->uuid,
                'cohort_name' => $membership->cohort->name,
                'joined_at' => $membership->joined_at,
            ],
            'pending_request' => $pending === null ? null : TransferRequestResource::make($pending)->toArray($request),
            'cohorts' => $cohorts
                ->map(fn (Cohort $cohort): array => CohortResource::make(
                    $cohort,
                    $preview[(int) $cohort->getKey()] ?? [],
                )->toArray($request))
                ->all(),
        ]);
    }

    public function join(Request $request, Cohort $cohort, JoinCohort $action): JsonResponse
    {
        try {
            $membership = $action->handle($cohort, $this->currentUser($request));
        } catch (CohortRefusal $refusal) {
            return $this->refusal($refusal);
        }

        return response()->json([
            'cohort_uuid' => $cohort->uuid,
            'joined_at' => $membership->joined_at,
        ], 201);
    }

    public function requestTransfer(Request $request, Cohort $cohort, RequestTransfer $action): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $transfer = $action->handle($cohort, $this->currentUser($request), $validated['reason'] ?? null);
        } catch (CohortRefusal $refusal) {
            return $this->refusal($refusal);
        }

        return response()->json(
            TransferRequestResource::make($transfer->load(['toCohort', 'fromCohort'])),
            201,
        );
    }

    public function withdraw(Request $request, CohortTransferRequest $transferRequest, WithdrawTransferRequest $action): JsonResponse
    {
        // The ownership test is the whole guard — a student holds no workspace
        // role, so a permission check here would deny the person the row is.
        $this->authorize('withdraw', $transferRequest);

        try {
            $action->handle($transferRequest, $this->currentUser($request));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'تم سحب الطلب.']);
    }

    /**
     * ⚠️ THE CODE TRAVELS BESIDE THE SENTENCE. The screen switches on the code —
     * «اكتملت» greys one card while «already_member» sends the reader to the
     * transfer form — and asserting on Arabic prose would mean the wording can
     * never be improved again.
     */
    private function refusal(CohortRefusal $refusal): JsonResponse
    {
        return response()->json([
            'message' => $refusal->getMessage(),
            'code' => $refusal->refusalCode,
        ], $refusal->status);
    }
}
