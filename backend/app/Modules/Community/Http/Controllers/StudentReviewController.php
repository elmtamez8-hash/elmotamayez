<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Community\Http\Resources\PeriodicReviewResource;
use App\Modules\Community\Models\PeriodicReview;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * What the student — or their authorised guardian — reads (FR-029 · FR-035).
 *
 * ⚠️ THE FILTER IS EXPLICIT AND THE SCOPE IS DROPPED, BECAUSE `WorkspaceScope` IS
 * INERT FOR A STUDENT. A student is a member of no workspace, so
 * `WorkspaceContext::id()` is null and the global scope adds no condition at all —
 * `BelongsToWorkspace` protects NOTHING on this route. The guard is
 * `student_user_id = <this person>`, which is ownership rather than a scope, and
 * without it this endpoint returns every assessment on the platform. Same shape as
 * `GET /gamification/redemptions` in 009.
 *
 * ⚠️ AND `whereNotNull('published_at')`. A draft is the teacher's until they
 * finish it; leaked here, the conditional claim in `PublishPeriodicReview` guards
 * nothing that matters and the student reads a half-written judgement of
 * themselves.
 */
class StudentReviewController extends Controller
{
    public function index(Request $request, GuardianDirectory $guardians): AnonymousResourceCollection
    {
        $subject = $this->subject($request, $guardians);

        $reviews = PeriodicReview::query()
            ->withoutGlobalScopes()
            ->where('student_user_id', $subject->getKey())
            ->whereNotNull('published_at')
            ->with('teacher:id,uuid,first_name,last_name')
            ->orderByDesc('period_start')
            ->get();

        return PeriodicReviewResource::collection($reviews);
    }

    /**
     * Whose assessments these are: the caller's own, or a child they are
     * authorised for.
     *
     * ⚠️ THE CHILD IS MATCHED INSIDE THE AUTHORISED LIST, never fetched by uuid and
     * checked afterwards — a bare uuid parameter is an identity probe, and the
     * response would come back carrying a name whether or not the relation exists.
     * `BillingController::childBalance()` spells the same rule for money.
     */
    private function subject(Request $request, GuardianDirectory $guardians): User
    {
        $requested = $request->query('student');
        $caller = $this->currentUser($request);

        if (! is_string($requested) || $requested === '' || $requested === $caller->uuid) {
            return $caller;
        }

        $child = $guardians
            ->childrenOf($caller, GuardianPermission::Results)
            ->firstWhere('uuid', $requested);

        // 403 rather than 404: the two answers differ, and the difference tells an
        // unauthorised reader whether that uuid names a real person.
        abort_if($child === null, 403);

        return $child;
    }
}
