<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Enums\ModerationVerdict;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\Marketplace\Models\Review;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * «أبلِغ عن هذا التقييم» — FR-034, and it is the EXISTING moderation path rather
 * than a second one.
 *
 * ⚠️ A REVIEW IS ALREADY MODERATABLE — `ModerateReview` hides one and
 * `marketplace.reviews.moderate` gates it. What was missing is the door a reader
 * comes in through: an abusive review sat on a teacher's public profile with no
 * way for anybody to raise it except by knowing an admin. So this writes a
 * `Reported` row into the same `moderation_actions` table the chat uses, read by
 * the same queue, rather than growing a parallel report table with its own screen
 * that somebody has to remember to look at.
 *
 * ⚠️ AND IT HIDES NOTHING. A report that hid content on submission is a mute
 * button handed to whoever complains first — the rule `ReportMessage` already
 * spells, and a review is the one place it would be aimed at a teacher's living.
 *
 * The lookup crosses into Marketplace's model and the WRITE stays here, which is
 * the direction that keeps `moderation_actions` owned by one module.
 */
class ReportReview extends Action
{
    public function handle(User $reporter, string $reviewUuid, ?string $reason): ModerationAction
    {
        $review = Review::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $reviewUuid)
            // A hidden review is not on any profile, so reporting one by uuid is a
            // probe for what a moderator has already taken down.
            ->where('is_visible', true)
            ->first();

        if (! $review instanceof Review) {
            throw new ModelNotFoundException('لم نجد هذا التقييم.');
        }

        return ModerationAction::query()->create([
            'workspace_id' => $review->workspace_id,
            'actor_user_id' => $reporter->getKey(),
            'subject_type' => ModerationAction::SUBJECT_REVIEW,
            'subject_id' => $review->getKey(),
            'verdict' => ModerationVerdict::Reported,
            'reason' => $reason,
        ]);
    }
}
