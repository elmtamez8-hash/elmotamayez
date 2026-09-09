<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\PublishPeriodicReview;
use App\Modules\Community\Actions\SubmitPeriodicReview;
use App\Modules\Community\Data\PeriodicReviewData;
use App\Modules\Community\Http\Requests\SavePeriodicReviewRequest;
use App\Modules\Community\Http\Resources\PeriodicReviewResource;
use App\Modules\Community\Models\PeriodicReview;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The teacher's side of the periodic assessment (FR-028).
 *
 * ⚠️ THE `{review}` BINDING IS IMPLICIT AND THAT IS SAFE ONLY BECAUSE THE READER
 * IS A MEMBER — the same exemption `AssistantController` documents. `periodic_reviews`
 * is workspace-scoped, so another teacher's uuid 404s before the policy runs.
 * Nothing on the STUDENT side may copy it: a student is a member of no workspace,
 * `WorkspaceScope` adds no condition for them, and an implicit binding there
 * resolves any row on the platform.
 */
class PeriodicReviewController extends Controller
{
    /** Every assessment this teacher has written for one student, newest first. */
    public function index(Request $request, string $studentUuid): AnonymousResourceCollection
    {
        $this->authorize('manage', PeriodicReview::class);

        $reviews = PeriodicReview::query()
            ->whereHas('student', fn ($query) => $query->where('uuid', $studentUuid))
            ->with('teacher:id,uuid,first_name,last_name')
            ->orderByDesc('period_start')
            ->get();

        return PeriodicReviewResource::collection($reviews);
    }

    public function store(SavePeriodicReviewRequest $request, SubmitPeriodicReview $action): JsonResponse
    {
        $this->authorize('manage', PeriodicReview::class);

        $review = $action->handle(
            $this->workspace(),
            $this->currentUser($request),
            PeriodicReviewData::fromArray($request->validated()),
        );

        return response()->json(
            PeriodicReviewResource::make($review->load('teacher:id,uuid,first_name,last_name')),
            $review->wasRecentlyCreated ? 201 : 200,
        );
    }

    /**
     * Publishing is idempotent by a conditional UPDATE inside the Action, so a
     * second call answers 200 with the same row and sends nothing.
     */
    public function publish(Request $request, PeriodicReview $review, PublishPeriodicReview $action): JsonResponse
    {
        $this->authorize('manage', PeriodicReview::class);

        return response()->json(
            PeriodicReviewResource::make($action->handle($review)->load('teacher:id,uuid,first_name,last_name')),
        );
    }

    private function workspace(): Workspace
    {
        $workspace = app(WorkspaceContext::class)->current();

        abort_if($workspace === null, 403);

        return $workspace;
    }
}
