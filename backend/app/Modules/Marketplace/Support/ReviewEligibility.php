<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Community\Support\CommunitySettings;
use App\Modules\Marketplace\Models\Review;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Contracts\SessionAttendanceDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * May this student rate this teacher, and what period would the rating land in?
 *
 * ⚠️ ONE CLASS BECAUSE THERE IS ONE QUESTION. `SubmitReview` refuses with it and
 * `ReadReviewEligibility` offers with it — build the offer beside the gate and the
 * screen shows a form the server rejects, or hides one it would have accepted.
 * That is the `ListLeaderboardScopes` defect, named in this spec's own task list,
 * and it is why `SC-010`'s test walks the offered answer through the real endpoint.
 *
 * ⚠️ AND THE GATE IS ATTENDANCE, NOT A COMPLETED ENROLMENT. The shipped rule was
 * `hasCompletedSessionWith()` — a finished enrolment — which refuses a student who
 * has sat four live lessons on an active enrolment and admits one who finished a
 * self-paced course and never met the teacher. FR-030 asks for sessions counted as
 * attended, so that is what is counted. The old rule is replaced in place; there is
 * no second endpoint.
 */
final class ReviewEligibility
{
    public function __construct(private readonly SessionAttendanceDirectory $attendance) {}

    /**
     * @return array{
     *     eligible: bool,
     *     attended_sessions: int,
     *     required_sessions: int,
     *     period_start: string,
     *     period_end: string,
     *     is_revision: bool,
     *     reason: string|null,
     * }
     */
    public function for(TeacherProfile $teacher, User $student): array
    {
        $required = CommunitySettings::reviewMinSessions();
        $attended = $this->attendance->attendedSessionCountInWorkspace($student, (int) $teacher->workspace_id);

        [$start, $end, $existing] = $this->period($teacher, $student);

        // The refusal names what is missing, never «you may not» — FR-030's own
        // wording, and the difference between a student who waits two more
        // lessons and one who gives up on the form.
        $reason = $attended >= $required
            ? null
            : sprintf('لا يمكن التقييم قبل حضور %d حصص. حضرت %d حتى الآن.', $required, $attended);

        return [
            'eligible' => $reason === null,
            'attended_sessions' => $attended,
            'required_sessions' => $required,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'is_revision' => $existing !== null,
            'reason' => $reason,
        ];
    }

    /**
     * The row this student's next rating belongs to, and the window around it.
     *
     * ⚠️ THE PERIOD IS DERIVED FROM THE LAST REVIEW, NOT FROM AN EPOCH. Counting
     * windows from a fixed origin would move every student's boundary on the same
     * day whatever their history, and a student who joined yesterday would find
     * their first period already three days from closing. A rating inside the live
     * window UPDATES its row — FR-019's revision, which is also why FR-032 holds:
     * one row per period, not one write.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: Review|null}
     */
    public function period(TeacherProfile $teacher, User $student): array
    {
        $days = CommunitySettings::reviewPeriodDays();

        $latest = $this->existingQuery($teacher, $student)
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->first();

        if ($latest !== null) {
            $start = CarbonImmutable::parse($latest->period_start)->startOfDay();

            if ($start->addDays($days)->isFuture()) {
                return [$start, $start->addDays($days - 1), $latest];
            }
        }

        $start = CarbonImmutable::now()->startOfDay();

        return [$start, $start->addDays($days - 1), null];
    }

    /**
     * ⚠️ BOTH ENDS PINNED BY EXPLICIT COLUMNS AND THE SCOPE DROPPED. A marketplace
     * student belongs to no workspace, so `WorkspaceScope` adds no condition for
     * them anyway — dropping it deliberately says so rather than relying on the
     * reader happening to be scopeless.
     *
     * @return Builder<Review>
     */
    private function existingQuery(TeacherProfile $teacher, User $student)
    {
        return Review::query()
            ->withoutWorkspaceScope()
            ->where('teacher_profile_id', $teacher->getKey())
            ->where('student_id', $student->getKey());
    }
}
