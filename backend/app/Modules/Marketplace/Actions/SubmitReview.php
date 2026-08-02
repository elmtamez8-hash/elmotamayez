<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Events\ReviewSubmitted;
use App\Modules\Marketplace\Models\Review;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Actions\Action;
use DomainException;

class SubmitReview extends Action
{
    /**
     * The rule that only a student who actually studied with this teacher may rate
     * them lives here, not in the FormRequest (FR-018, Constitution II): Filament
     * and the seeders reach the same Action without ever building a request.
     */
    public function handle(TeacherProfile $teacher, User $student, int $rating, ?string $comment = null): Review
    {
        if (! $this->hasCompletedSessionWith($teacher, $student)) {
            throw new DomainException('لا يمكن التقييم قبل إتمام حصة مع هذا المدرّس.');
        }

        $review = Review::query()
            ->withoutWorkspaceScope()
            ->firstOrNew([
                'teacher_profile_id' => $teacher->getKey(),
                'student_id' => $student->getKey(),
            ]);

        $review->fill([
            // A marketplace student belongs to no workspace, so the auto-fill has
            // nothing to resolve. The review is the teacher's workspace's data.
            'workspace_id' => $teacher->workspace_id,
            'rating' => $rating,
            'comment' => $comment,
        ])->save();

        event(new ReviewSubmitted($review->fresh() ?? $review));

        return $review;
    }

    /**
     * A completed enrollment in one of the teacher's own courses.
     *
     * Sessions as a first-class entity arrive with the Learning module later; until
     * then the completed enrollment is the same evidence — the student finished
     * something this teacher taught.
     *
     * Both queries deliberately drop WorkspaceScope: the reviewer is a marketplace
     * student with no workspace of their own, and the rows being checked belong to
     * the teacher's. Ownership is pinned by the explicit teacher/student columns.
     */
    private function hasCompletedSessionWith(TeacherProfile $teacher, User $student): bool
    {
        $courseIds = Course::query()
            ->withoutWorkspaceScope()
            ->where('created_by', $teacher->user_id)
            ->select('id');

        return Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $student->getKey())
            ->where('status', 'completed')
            ->whereIn('course_id', $courseIds)
            ->exists();
    }
}
