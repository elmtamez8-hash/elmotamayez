<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Support\PromoVideoUrl;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;

/**
 * The teacher pastes a link to their promotional video (018 · US2).
 *
 * ⚠️ WRITING THE ID AND CLEARING THE REVIEW ARE ONE STATEMENT, and that is the
 * requirement rather than tidiness. A course whose approval survives a swapped
 * link is a course where the review means nothing: paste something acceptable,
 * get approved, paste anything at all. Two statements leave a window where the
 * old approval stands over new content; one closes it.
 *
 * ⚠️ AND THE MARKETPLACE CONDITION IS ENFORCED HERE, NOT ONLY IN THE FORM
 * REQUEST (FR-009). Filament, the seeders and any future console command reach
 * this action with no form behind them — a rule that lives in a `FormRequest`
 * alone is a rule the admin panel walks around without noticing (constitution
 * II).
 *
 * The status is written directly rather than through mass assignment because it
 * is deliberately absent from `$fillable`: mass-assignable, it becomes a second
 * door onto the approval decision from outside the action that owns it — the
 * rule `captured_order_id` already wrote down.
 */
class SetCoursePromoVideo extends Action
{
    use LogsActivity;

    /**
     * @param  string|null  $url  the pasted link, or null to remove the video
     *
     * @throws DomainException when the link is not one we accept, or the
     *                         teacher is not publicly listed in the marketplace
     */
    public function handle(Course $course, ?string $url): Course
    {
        $this->assertPubliclyListedTeacher($course);

        if ($url === null || trim($url) === '') {
            $course->forceFill([
                'promo_video_id' => null,
                'promo_video_status' => Course::PROMO_NONE,
                'promo_video_reviewed_at' => null,
                'promo_video_reviewed_by' => null,
            ])->save();

            $this->logActivity('promo_video_cleared', $course);

            return $course;
        }

        $videoId = PromoVideoUrl::extract($url);

        if ($videoId === null) {
            // Refused at the door, never at display time: a rejected link stored
            // for later is a row sitting in the database waiting to be embedded,
            // and it lives until somebody happens to find it.
            throw new DomainException(
                'الرابط غير مقبول. الصق رابط فيديو من يوتيوب، مثل https://youtu.be/… أو https://www.youtube.com/watch?v=…'
            );
        }

        $course->forceFill([
            'promo_video_id' => $videoId,
            // One statement with the id above it. See the class docblock.
            'promo_video_status' => Course::PROMO_PENDING,
            'promo_video_reviewed_at' => null,
            'promo_video_reviewed_by' => null,
        ])->save();

        $this->logActivity('promo_video_submitted', $course);

        return $course;
    }

    /**
     * FR-009 — only an approved, publicly listed teacher may put a video on a
     * public page.
     *
     * Read from the course's author rather than from the caller: the course is
     * what carries the video, and `created_by` is the person whose face is in
     * it. An assistant acting for the teacher is still publishing the teacher's
     * video.
     */
    private function assertPubliclyListedTeacher(Course $course): void
    {
        $profile = $course->creator?->teacherProfile;

        $listed = $profile !== null
            && $profile->is_publicly_listed
            && $profile->approval_status === TeacherProfile::STATUS_APPROVED;

        if (! $listed) {
            throw new DomainException('لا يمكن إضافة فيديو ترويجي قبل اعتماد الحساب ونشره في السوق.');
        }
    }
}
