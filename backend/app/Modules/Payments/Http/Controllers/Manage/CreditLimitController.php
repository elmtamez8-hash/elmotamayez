<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Actions\SetCreditLimit;
use App\Modules\Payments\Http\Requests\UpdateCreditLimitRequest;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Support\CreditAccounts;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;

/**
 * The exception FR-038 allows, and the record FR-039 requires.
 *
 * Everything here is a 403, never a 404. A 404 for a student who is not yours and
 * a 403 for one who is answers the question "does this person exist" for anyone
 * who can spell a uuid — the same reason 005's `CreateFreezePeriod` asks
 * {@see EnrollmentDirectory} before it writes rather than trusting
 * `exists:users,uuid`, which answers a different question entirely.
 *
 * ⚠️ THE STUDENT IS NOT ROUTE-MODEL-BOUND. `{student}` as a `User` resolves by
 * uuid before any guard in this method runs, and the first thing that touches the
 * resolved model turns a bare uuid into a fact about a real person.
 *
 * ⚠️ AND THE PERMISSION IS CHECKED FIRST, before any lookup. A teacher — who
 * holds none of these grants — must learn nothing from the shape of the refusal,
 * including whether the ids they sent mean anything.
 *
 * The limit is per BALANCE, so the course is required: a ceiling on "the student"
 * would be a ceiling on all their teachers at once, and Q-7 put the balance on the
 * course precisely so the paid-up course stays open.
 */
class CreditLimitController extends Controller
{
    public function update(
        UpdateCreditLimitRequest $request,
        string $student,
        SetCreditLimit $action,
        CreditAccounts $accounts,
        EnrollmentDirectory $enrollments,
        WorkspaceContext $context,
    ): JsonResponse {
        $actor = $this->currentUser($request);

        // Platform-level (R16) and asked FIRST: raising a ceiling creates a claim
        // on money with no payment leg behind it, and the teacher is the party
        // paid out of it. Asked before any lookup so a refused caller learns
        // nothing from the shape of the answer — and before `balanceFor()`, which
        // creates the row on first use and would otherwise leave a trace of a
        // request that was never allowed.
        $this->authorize('manageLimit', CreditBalance::class);

        $workspaceId = $context->id();

        abort_if($workspaceId === null, 403);

        $studentUser = User::query()->where('uuid', $student)->first();

        abort_if($studentUser === null, 403);

        // NFR-001أ — nothing may be learnt about someone with no active enrolment
        // in this workspace, and that includes whether they hold a balance.
        abort_unless($enrollments->hasActiveEnrollmentInWorkspace($studentUser, $workspaceId), 403);

        $course = Course::query()->where('uuid', $request->string('course')->toString())->first();

        abort_if($course === null, 403);

        $balance = $accounts->balanceFor($studentUser, $course);

        $limit = $action->handle(
            $balance,
            $request->integer('credit_limit_credits'),
            $request->string('reason')->toString(),
            $actor,
        );

        return response()->json(['data' => ['credit_limit_credits' => $limit]]);
    }
}
