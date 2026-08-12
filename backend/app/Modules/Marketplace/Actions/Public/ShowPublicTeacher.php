<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\Review;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolve a single teacher for the public profile page.
 *
 * Route-model binding is deliberately NOT used: it resolves by uuid without the
 * publiclyListed() guard, which on a guest request means any workspace's draft
 * profile is reachable by url.
 */
class ShowPublicTeacher extends Action
{
    /**
     * Resolve by slug, and by uuid as well.
     *
     * The uuid was the public URL until the slug replaced it, so every link
     * already shared — a WhatsApp message, a bookmark, a search result — is a
     * uuid. Refusing it turns a rename of the URL scheme into a wave of 404s on
     * pages that still exist. The page redirects to the canonical slug so only
     * one of the two is ever indexed.
     *
     * One `orWhere`, not a shape test on the string: a uuid is recognisable, but
     * a rule that decides which column to search from the FORM of the input is
     * one malformed uuid away from silently searching the wrong one.
     */
    public function handle(string $key): TeacherProfile
    {
        $teacher = TeacherProfile::query()
            ->publiclyListed()
            ->with([
                'user:id,first_name,last_name',
                'subjects',
                'gradeLevels',
                'availabilitySlots',
            ])
            ->where(function ($query) use ($key): void {
                $query->where('teacher_profiles.slug', $key)
                    ->orWhere('teacher_profiles.uuid', $key);
            })
            ->first();

        if ($teacher === null) {
            // One response for "no such teacher", "not approved", "not published"
            // and "workspace withdrew". Distinguishing them would confirm that an
            // unpublished profile exists.
            throw new NotFoundHttpException('غير متاح');
        }

        return $teacher;
    }

    /**
     * The public reviews block: average, star distribution and the newest comments.
     *
     * Hidden reviews are excluded everywhere, including the distribution — a bar
     * chart that counts rows the list does not show reads as a bug.
     *
     * @return array<string, mixed>
     */
    public function reviewsOf(TeacherProfile $teacher, int $limit = 20): array
    {
        $reviews = Review::query()
            ->withoutWorkspaceScope()
            ->where('teacher_profile_id', $teacher->getKey())
            ->where('is_visible', true)
            // The nested select needs `user_id`: without the foreign key the
            // hasOne has nothing to match on and every profile comes back null,
            // which reads as "no student uploaded a photo" rather than as a bug.
            ->with([
                'student:id,first_name,last_name',
                'student.studentProfile:id,user_id,avatar_path',
            ])
            ->orderByDesc('created_at')
            ->get();

        $distribution = ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0];

        foreach ($reviews as $review) {
            $distribution[(string) $review->rating]++;
        }

        return [
            'average' => $reviews->isEmpty() ? null : round((float) $reviews->avg('rating'), 2),
            'total' => $reviews->count(),
            // Cast to object: PHP turns numeric string keys into integers and
            // json_encode would then emit an array instead of the keyed object.
            'distribution' => (object) $distribution,
            // The `teacher_*` keys the home carousel adds are deliberately absent
            // here: this page IS the teacher, and repeating their photo on every
            // row would be the same image sent twenty times to say nothing.
            'items' => $reviews->take($limit)->map(function (Review $review): array {
                $avatar = $review->student?->studentProfile?->avatar_path;

                return [
                    'student_display_name' => $review->studentDisplayName(),
                    // Published here and nowhere else — see the note on
                    // PublicFieldAllowlist::REVIEW for what that costs and who
                    // decided to pay it.
                    'student_avatar_url' => $avatar === null ? null : asset('storage/'.$avatar),
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'created_at' => $review->created_at?->toDateString(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * The teacher's own publicly listed courses (FR-058).
     *
     * A separate query rather than a relation on TeacherProfile: courses point at
     * a user, not a profile, and adding a second path to the same rows is how the
     * two start disagreeing.
     *
     * @return Collection<int, Course>
     */
    public function coursesOf(TeacherProfile $teacher): Collection
    {
        return Course::query()
            ->publiclyListed()
            ->where('created_by', $teacher->user_id)
            ->with(['creator:id,first_name,last_name', 'creator.teacherProfile'])
            ->withCount([
                // Scoped, and it was not. `withCount('lessons')` counts every row
                // — so a teacher's half-written drafts and their retired archive
                // both inflated the number a visitor is shown, and the course
                // advertised more items than it opens. `FR-062` bans a draft
                // item's fields from a public payload, and a count computed from
                // those items is the same leak arriving as one number.
                'lessons as lessons_count' => fn ($query) => $query->visibleToStudents(),
                'enrollments as enrolled_count',
            ])
            ->orderByDesc('created_at')
            ->get();
    }
}
