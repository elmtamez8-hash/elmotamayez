<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Listeners;

use App\Modules\Assessments\Events\ExamFailed;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;

/**
 * The academic warning (`academic_warning`, owner decision 2026-09-24).
 *
 * A student who fails N GRADED exams in a row in ONE course — N being
 * `assessments.academic_warning_consecutive_fails`, default two, zero off — is
 * warned once, with every guardian holding the `AcademicWarnings` consent (the
 * type's own `requiredGuardianPermission()`; `DispatchNotification` fans out).
 *
 * `ExamFailed` is the trigger because it already means exactly the inputs the
 * rule counts: `FinalizeAttempt` fires it only for a NON-PRACTICE attempt whose
 * STORED `passed` is false, at the moment the score became final — so an essay
 * paper waiting on a grader counts only once somebody has marked it.
 *
 * ⚠️ THE STREAK IS READ AS OF THE FAILING ATTEMPT, NEVER AS OF NOW. The window
 * is this student's graded, non-practice attempts on exams of this course,
 * finalized AT OR BEFORE this one (`finalized_at`, then id), newest first, N+1
 * rows. It warns only when the first N all failed AND the (N+1)th is missing
 * or a pass — i.e. only on the failure that makes the run EXACTLY N long. So:
 *   · fail, pass, fail — the second fail's window holds a pass: nothing;
 *   · a third fail after the warning — its (N+1)th row is a fail: nothing;
 *   · a pass then N new fails — a fresh run of exactly N: warned again.
 * Read "as of now" instead, two papers graded a second apart would each see
 * the other and both warn — or neither.
 *
 * ⚠️ AND IT IS CLAIMED, NOT ONLY DERIVED. `ReviseGrade` re-finalizes a paper
 * after a corrected mark, which fires `ExamFailed` again — and moves that
 * attempt's `finalized_at`, so a re-finalized EARLIER failure can land at the
 * top of the same run. So the listener refuses any window that already holds
 * a stamped attempt, and stamps the completing attempt's `academic_warning_at`
 * with a conditional UPDATE before the dispatch. Handed back only if the
 * dispatch recorded nothing (a missing template), as `SendAbsenceAlerts` does.
 *
 * Queued and after commit: `FinalizeAttempt` fires the event inside its
 * transaction, and a warning about a score that rolled back is a lie.
 */
class WarnOnConsecutiveFailures implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(ExamFailed $event): void
    {
        $threshold = (int) PlatformSettings::get('assessments.academic_warning_consecutive_fails', 2);

        if ($threshold < 1) {
            return;
        }

        $attempt = Attempt::query()->withoutWorkspaceScope()->whereKey($event->attempt->getKey())->first();

        if ($attempt === null
            || $attempt->is_practice
            || $attempt->passed
            || $attempt->status !== Attempt::STATUS_GRADED
            || $attempt->finalized_at === null
            || $attempt->exam_id === null) {
            return;
        }

        $exam = DB::table('exams')->where('id', $attempt->exam_id)->first(['course_id', 'title']);

        if ($exam === null || $exam->course_id === null) {
            return;
        }

        $finalizedAt = $attempt->finalized_at;
        $id = (int) $attempt->getKey();

        $window = DB::table('exam_attempts')
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->where('exams.course_id', $exam->course_id)
            ->where('exam_attempts.student_user_id', $attempt->student_user_id)
            ->where('exam_attempts.is_practice', false)
            ->where('exam_attempts.status', Attempt::STATUS_GRADED)
            ->whereNotNull('exam_attempts.finalized_at')
            ->where(function ($query) use ($finalizedAt, $id): void {
                $query->where('exam_attempts.finalized_at', '<', $finalizedAt)
                    ->orWhere(function ($same) use ($finalizedAt, $id): void {
                        $same->where('exam_attempts.finalized_at', '=', $finalizedAt)
                            ->where('exam_attempts.id', '<=', $id);
                    });
            })
            ->orderByDesc('exam_attempts.finalized_at')
            ->orderByDesc('exam_attempts.id')
            ->limit($threshold + 1)
            ->get(['exam_attempts.id', 'exam_attempts.passed', 'exam_attempts.academic_warning_at']);

        $top = $window->first();

        if ($top === null || $window->count() < $threshold || (int) $top->id !== $id) {
            return;
        }

        $run = $window->take($threshold);

        if ($run->contains(static fn (object $row): bool => (bool) $row->passed)
            || $run->contains(static fn (object $row): bool => $row->academic_warning_at !== null)) {
            return;
        }

        $before = $window->get($threshold);

        if ($before !== null && ! (bool) $before->passed) {
            // The run is already longer than N: the warning went out when it
            // reached N, and this is a further failure inside the same run.
            return;
        }

        $this->warn($attempt, (string) $exam->title, $this->courseTitle((int) $exam->course_id));
    }

    private function warn(Attempt $attempt, string $examTitle, string $courseTitle): void
    {
        $student = $attempt->student;

        if ($student === null) {
            return;
        }

        $claimed = DB::table('exam_attempts')
            ->where('id', $attempt->getKey())
            ->whereNull('academic_warning_at')
            ->update(['academic_warning_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $sent = $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::AcademicWarning,
            variables: [
                'student_name' => $student->name,
                'course_title' => $courseTitle,
                // No numeral: the threshold is an operator's number, and an
                // Arabic count agrees its noun in five bands — «لم يجتز الاختبارات
                // الأخيرة» is true of any N without a counted noun to get wrong.
                'note' => 'لم يجتز الاختبارات الأخيرة المتتالية في هذه الدورة، وآخرها «'.$examTitle.'».',
            ],
            actionUrl: '/exams',
            subject: $student,
            workspaceId: (int) $attempt->workspace_id,
        ));

        if ($sent->isEmpty()) {
            DB::table('exam_attempts')
                ->where('id', $attempt->getKey())
                ->update(['academic_warning_at' => null]);
        }
    }

    private function courseTitle(int $courseId): string
    {
        $title = DB::table('courses')->where('id', $courseId)->value('title');

        return is_string($title) && $title !== '' ? $title : 'الدورة';
    }
}
