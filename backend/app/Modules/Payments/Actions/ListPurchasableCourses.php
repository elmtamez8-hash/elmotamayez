<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Support\CourseParticipation;
use App\Shared\Actions\Action;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The courses this student may have credits bought on — the picker's source.
 *
 * ⛔ AND IT IS NOT `GET /enrollments?student_uuid=`, WHICH WAS THE FIRST DESIGN.
 * `isPartyTo()` has THREE arms and a list of enrolments covers ONE. The second —
 * an active enrolment anywhere in the teacher's workspace — opens every course
 * of that teacher, so an enrolment-built picker HIDES courses the server accepts
 * and the guardian never learns they could have bought on them. That is SC-003
 * broken in its quiet direction, and a literal copy of the defect
 * `ListLeaderboardScopes` was written to fix: a picker assembled BESIDE the
 * authoriser rather than derived FROM it.
 *
 * So the source is {@see CourseParticipation::coursesOpenTo()}, the plural of the
 * predicate the door itself asks, and `PurchasableCoursesTest` walks every option
 * it returns back through the real `isPartyTo()`.
 *
 * ⚠️ PUBLISHED IS FILTERED HERE AND STALLED IS NOT, AND THE ASYMMETRY IS THE
 * POINT. A draft course is one the teacher has never released — offering it is
 * showing a buyer something that does not exist yet, and `StopSellingGuard`
 * refuses it with a sentence that deliberately does not say why. A course that
 * has stopped DELIVERING is public, was sold before, and answers with the
 * packages screen's own empty state, which already explains itself; hiding it
 * would tell a returning student their course had vanished. (`courses` carries no
 * `published_at`, so `status = 'published'` here is exactly `isPublished()` — the
 * day that column arrives, this needs its second condition.)
 *
 * ⚠️ AND IT IS PAGINATED. The third arm is workspace membership, which is bounded
 * by nothing: an academy may hold hundreds of courses, and a picker that returns
 * all of them is a payload that grows with somebody else's catalogue.
 */
class ListPurchasableCourses extends Action
{
    public function __construct(private readonly CourseParticipation $participation) {}

    /**
     * @return LengthAwarePaginator<int, Course>
     */
    public function handle(User $student, ?User $payer = null, int $perPage = 20): LengthAwarePaginator
    {
        return $this->participation->coursesOpenTo($student, $payer)
            ->where('status', 'published')
            /*
            | The academy's name is what the student knows the teacher by, and it
            | is the only way two courses called «الفيزياء ٣» tell each other
            | apart. Eager-loaded rather than read per row: a Resource runs once
            | per row, so a lookup inside one is an N+1 by construction.
            |
            | `Workspace` carries no `BelongsToWorkspace` — it IS the tenant — so
            | this relation needs no bypass of its own, unlike the `->with('order')`
            | that once answered «nothing was bought» with a 200.
            */
            ->with('workspace:id,name')
            ->orderBy('title')
            ->paginate($perPage);
    }
}
