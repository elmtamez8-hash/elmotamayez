<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\ModerateMessage;
use App\Modules\Community\Actions\ReportMessage;
use App\Modules\Community\Actions\ReportReview;
use App\Modules\Community\Data\ModerationActionData;
use App\Modules\Community\Http\Requests\ModerationActionRequest;
use App\Modules\Community\Http\Requests\ReportMessageRequest;
use App\Modules\Community\Http\Resources\ModerationActionResource;
use Illuminate\Http\JsonResponse;

/**
 * Hiding, banning, lifting — and the report anybody in a room may file.
 *
 * ⚠️ THERE IS NO `DELETE`. Lifting a ban is a new row with the `unbanned`
 * verdict; the table IS the record `FR-021` asks for, and a deleted row erases
 * who banned whom and why.
 */
class ModerationController extends Controller
{
    public function store(ModerationActionRequest $request, ModerateMessage $action): JsonResponse
    {
        $moderation = $action->handle(
            $this->currentUser($request),
            ModerationActionData::fromArray($request->validated()),
        );

        return ModerationActionResource::make($moderation)->response()->setStatusCode(201);
    }

    public function report(ReportMessageRequest $request, string $message, ReportMessage $action): JsonResponse
    {
        $action->handle(
            $this->currentUser($request),
            $message,
            $request->validated('reason'),
        );

        /*
        | ⚠️ NO BODY, AND NOTHING ABOUT WHAT WAS FOUND. A reporter learning whether
        | their report was the first, or what the moderator already knows, is an
        | oracle over another person's record — the same uniform-answer rule the
        | payment webhook and the breach report both follow.
        */
        return response()->json(['message' => 'وصلنا بلاغك، وسيطّلع عليه المدرّس.'], 202);
    }

    /**
     * The same door for a public review (FR-034).
     *
     * ⚠️ THE SAME `202` HOWEVER MANY TIMES IT IS FILED. A reporter learning that
     * theirs was not the first is an oracle over another person's record. A review
     * that is not there answers 404, exactly as `ReportMessage` does — and a review
     * a moderator has ALREADY HIDDEN answers 404 too, which is the pairing that
     * matters: «taken down» and «never existed» must not be distinguishable, while
     * the uuid of a visible review is public by construction.
     */
    public function reportReview(ReportMessageRequest $request, string $review, ReportReview $action): JsonResponse
    {
        $action->handle(
            $this->currentUser($request),
            $review,
            $request->validated('reason'),
        );

        return response()->json(['message' => 'وصلنا بلاغك، وسيطّلع عليه فريق المنصّة.'], 202);
    }
}
