<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The platform decides whether a promotional video may play on a public page
 * (018 · FR-006).
 *
 * ⚠️ THE PERMISSION IS PLATFORM-LEVEL AND NO TENANT ROLE MAY HOLD IT. A
 * workspace owner who could approve their own video makes the review a name
 * with nothing behind it — which is why `PromoVideoReviewTest` asserts the
 * owner is refused, as well as asserting the holder succeeds.
 *
 * ⚠️ AND THE READ DECLARES `withoutWorkspaceScope()`. `WorkspaceContext::id()`
 * falls back to `users.last_workspace_id` for EVERY user including platform
 * staff, so a scoped query here would show one workspace's courses and answer
 * «not found» for every other — and would pass its own test on a single
 * workspace fixture. The audit chain already shipped that exact bug once.
 */
class ReviewCoursePromoVideo extends Action
{
    use LogsActivity;

    /**
     * @param  string  $decision  Course::PROMO_APPROVED or Course::PROMO_REJECTED
     *
     * @throws AuthorizationException when the reviewer does not hold the platform permission
     * @throws DomainException when there is nothing to review, or a rejection carries no reason
     */
    public function handle(User $reviewer, string $courseUuid, string $decision, ?string $reason = null): Course
    {
        if (! $reviewer->can(Permissions::MARKETPLACE_PROMO_REVIEW)) {
            throw new AuthorizationException('لا تملك صلاحية مراجعة الفيديوهات الترويجية.');
        }

        if (! in_array($decision, [Course::PROMO_APPROVED, Course::PROMO_REJECTED], true)) {
            throw new DomainException('قرار المراجعة غير معروف.');
        }

        // A rejection with no reason is a refusal the teacher cannot act on;
        // they would paste the same link again.
        if ($decision === Course::PROMO_REJECTED && ($reason === null || trim($reason) === '')) {
            throw new DomainException('اذكر سبب الرفض ليتمكّن المدرّس من تصحيحه.');
        }

        // Platform-wide read: see the class docblock. The reviewer is not a
        // member of the workspace that owns the course, and the scope would
        // silently hide every course outside their own fallback workspace.
        $course = Course::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $courseUuid)
            ->firstOrFail();

        if ($course->promo_video_status === Course::PROMO_NONE || $course->promo_video_id === null) {
            throw new DomainException('لا يوجد فيديو مقدَّم لهذا الكورس.');
        }

        $course->forceFill([
            'promo_video_status' => $decision,
            'promo_video_reviewed_at' => now(),
            'promo_video_reviewed_by' => $reviewer->getKey(),
        ])->save();

        $this->logActivity('promo_video_'.$decision, $course, ['reason' => $reason]);

        return $course;
    }
}
