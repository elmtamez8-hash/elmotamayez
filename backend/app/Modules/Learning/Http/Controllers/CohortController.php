<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\ReadCohortRoster;
use App\Modules\Learning\Actions\RequestTransfer;
use App\Modules\Learning\Actions\WithdrawTransferRequest;
use App\Modules\Learning\Http\Resources\CohortResource;
use App\Modules\Learning\Http\Resources\TransferRequestResource;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Support\CohortRefusal;
use App\Shared\Contracts\CohortDirectory;
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
        private readonly CohortDirectory $cohorts,
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
            /*
            | ⛔ OWNERSHIP, NOT STATUS — AND THIS LINE WAS MISSING UNTIL 2026-09-05.
            | `Cohort::scopeGroup()` says why the public read filters on it, and
            | the reasoning applies here with more force: a private cohort is named
            | `'حصص خاصة — '.$student->name` and created `closed`, and the comment
            | directly above deliberately KEEPS `closed` in this picker. So one
            | accepted private-session request put a card carrying a named
            | classmate — and, through `schedulePreviewFor()` below, the times of
            | her private lessons — into every enrolled student's group list.
            | `CohortResource` emits no `individual_for_user_id`, so no client
            | could have filtered it out. 200, nothing logged.
            |
            | Her own private session reaches her through her BOOKING, which is
            | where a lesson somebody holds a seat in belongs.
            */
            ->group()
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

        /*
        | ⚠️ THE GROUPS THIS READER HAS LEFT (FR-046). The API has granted
        | permanent READ of an old group's thread since US4, and nothing in the
        | product linked to it — so a student who transferred lost every answer
        | they had been given, with the entitlement sitting unreachable behind a
        | uuid nobody showed them.
        |
        | Keyed by cohort rather than by row: rejoining writes a NEW membership
        | (the history is not rewritten), so a student who left and came back has
        | two closed rows for one thread. And the group they are in NOW is
        | excluded — it is `membership` above, and listing it twice would offer
        | the current thread as an archive of itself.
        */
        $currentCohortId = $membership?->cohort_id;

        $past = CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('course_id', $courseId)
            ->whereNotNull('closed_at')
            ->when($currentCohortId !== null, fn ($query) => $query->where('cohort_id', '!=', $currentCohortId))
            ->with('cohort')
            ->orderByDesc('closed_at')
            ->get()
            ->unique('cohort_id')
            ->values();

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
            'past_cohorts' => $past
                ->map(fn (CohortMembership $row): array => [
                    'uuid' => $row->cohort->uuid,
                    'name' => $row->cohort->name,
                    'left_at' => $row->closed_at,
                ])
                ->all(),
            'pending_request' => $pending === null ? null : TransferRequestResource::make($pending)->toArray($request),
            'cohorts' => $cohorts
                ->map(fn (Cohort $cohort): array => CohortResource::make(
                    $cohort,
                    $preview[(int) $cohort->getKey()] ?? [],
                )->toArray($request))
                ->all(),
        ]);
    }

    /**
     * The classmates (FR-050) — a name, a face, a level, a rank and badges.
     *
     * ⚠️ THE DOOR IS `isCurrentMember`, AND IT DELIBERATELY DIFFERS FROM THE
     * THREAD'S. The group's chat admits whoever was EVER a member, because
     * FR-046 grants that archive for ever — it is a record of what was said while
     * they were there. This is a LIVE list of who is in the group today, and no
     * requirement gives somebody who left continuing sight of it. Unifying the
     * two doors "for consistency" would widen this one silently.
     *
     * ⚠️ AND THERE IS NO TEACHER BRANCH HERE. `/manage/cohorts/{cohort}/members`
     * is the teacher's list and carries what a teacher may see; a second entrance
     * to a second answer is the two-spellings defect this module has already
     * fixed twice.
     */
    public function roster(Request $request, Cohort $cohort, ReadCohortRoster $action): JsonResponse
    {
        if (! $this->cohorts->isCurrentMember($this->currentUser($request), (int) $cohort->getKey())) {
            abort(403, 'هذه القائمة لأعضاء المجموعة.');
        }

        // `members`, not `data` — the payload is a roll, and the contract names it.
        return response()->json(['members' => $action->handle($cohort)]);
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
