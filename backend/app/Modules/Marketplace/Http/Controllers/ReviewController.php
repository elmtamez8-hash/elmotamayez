<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Actions\ConfirmComplaint;
use App\Modules\Marketplace\Actions\DismissComplaint;
use App\Modules\Marketplace\Actions\ModerateReview;
use App\Modules\Marketplace\Actions\Public\ShowPublicTeacher;
use App\Modules\Marketplace\Actions\ReadReviewEligibility;
use App\Modules\Marketplace\Actions\SubmitReview;
use App\Modules\Marketplace\Http\Requests\SubmitReviewRequest;
use App\Modules\Marketplace\Models\Complaint;
use App\Modules\Marketplace\Models\Review;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(
        SubmitReviewRequest $request,
        string $uuid,
        ShowPublicTeacher $teachers,
        SubmitReview $action,
    ): JsonResponse {
        // Resolved through the public Action: the review form only exists on the
        // public profile, so "who can be reviewed" and "who can be seen" are the
        // same question, answered in one place.
        $teacher = $teachers->handle($uuid);
        $student = $this->currentUser($request);

        abort_unless($student->can('create', [Review::class, $teacher]), 403);

        $validated = $request->validated();

        $review = $action->handle(
            $teacher,
            $student,
            [
                'punctuality' => (int) $validated['punctuality'],
                'clarity' => (int) $validated['clarity'],
                'engagement' => (int) $validated['engagement'],
            ],
            $validated['comment'] ?? null,
        );

        return response()->json([
            'uuid' => $review->uuid,
            // The derived star, echoed so the client shows the same number the
            // public profile will — never re-derived in TypeScript.
            'rating' => $review->rating,
            // 201 on the first review, 200 on a revision (FR-019) — the client shows
            // "شكراً لتقييمك" either way, but an API that lies about creation is one
            // more thing to un-learn later.
        ], $review->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * What the form should show before it is filled in (FR-030 · `SC-010`).
     *
     * Resolved through the same public Action as `store()`: an unlisted profile is
     * no more askable-about than it is viewable.
     */
    public function eligibility(
        Request $request,
        string $uuid,
        ShowPublicTeacher $teachers,
        ReadReviewEligibility $action,
    ): JsonResponse {
        return response()->json(
            $action->handle($teachers->handle($uuid), $this->currentUser($request)),
        );
    }

    public function moderate(Request $request, string $uuid, ModerateReview $action): JsonResponse
    {
        $this->authorizePermission($request, Permissions::MARKETPLACE_REVIEWS_MODERATE);

        $action->handle(Review::query()->where('uuid', $uuid)->firstOrFail());

        return response()->json(['is_visible' => false]);
    }

    public function confirmComplaint(Request $request, string $uuid, ConfirmComplaint $action): JsonResponse
    {
        $this->authorizePermission($request, Permissions::MARKETPLACE_COMPLAINTS_MANAGE);

        $complaint = $action->handle($this->complaint($uuid));

        return response()->json(['status' => $complaint->status]);
    }

    public function dismissComplaint(Request $request, string $uuid, DismissComplaint $action): JsonResponse
    {
        $this->authorizePermission($request, Permissions::MARKETPLACE_COMPLAINTS_MANAGE);

        $complaint = $action->handle($this->complaint($uuid));

        return response()->json(['status' => $complaint->status]);
    }

    private function complaint(string $uuid): Complaint
    {
        return Complaint::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($this->currentUser($request)->can($permission), 403);
    }
}
