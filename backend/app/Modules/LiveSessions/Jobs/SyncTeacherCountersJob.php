<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fills the counters `teacher_profiles` has carried since spec 001 with nothing
 * writing to them.
 *
 * `attendance_rate` here means the TEACHER's own attendance: the share of
 * scheduled sessions they actually delivered (FR-062). A student's absence never
 * touches it (FR-063 · SC-019) — absence is the student's behaviour, and marking
 * a teacher down for it would let one unreliable student lower a profile the
 * marketplace ranks on.
 *
 * That reading is not the obvious one from the column's name, which is exactly
 * why it is written here, in docs/README.md, and in a test that fails if anyone
 * changes it.
 *
 * Aggregated per session rather than derived at read time (FR-027 · SC-011): a
 * public profile must not run a full history scan every time someone opens it.
 */
class SyncTeacherCountersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $teacherProfileId,
    ) {}

    public function handle(WorkspaceContext $context): void
    {
        $profile = TeacherProfile::query()->withoutWorkspaceScope()->find($this->teacherProfileId);

        if ($profile === null) {
            return;
        }

        // forWorkspace, never set(): a singleton that caches its answer would
        // leak this workspace into the next job the same worker picks up. A test
        // fails the build if a set() ever appears under Jobs/.
        $context->forWorkspace((int) $profile->workspace_id, function () use ($profile): void {
            $sessions = ClassSession::query()
                ->where('teacher_profile_id', $profile->getKey())
                ->get(['id', 'status', 'delivered_at', 'starts_at']);

            // Cancelled and suspended sessions are excluded from both sides of
            // the ratio (FR-026): a holiday is not a failure to teach.
            $countable = $sessions->filter(
                fn (ClassSession $session): bool => $session->status->countsTowardsCounters()
                    || $session->status === ClassSessionStatus::Interrupted,
            );

            $delivered = $countable->filter(fn (ClassSession $session): bool => $session->delivered_at !== null);

            $profile->forceFill([
                'completed_sessions_count' => $delivered->count(),
                /*
                | ⚠️ THIS COLUMN HAD THREE READERS AND NO WRITER, AND THE PROOF WAS
                | IN PRODUCTION: 11 delivered sessions beside 0 students taught.
                | The home page's «طلاب», every teacher's public «طلاب درّسهم» and
                | the panel's own column all read it, and nothing outside the
                | factory and the demo seeders had ever written it — so the number
                | was zero for every real teacher, permanently, with nothing
                | failing. It was omitted from THIS `forceFill`, one line from its
                | siblings. Reported by the owner, 2026-08-31.
                */
                'students_taught_count' => $this->studentsTaught($profile, array_values($delivered->pluck('id')->all())),
                'cancelled_sessions_count' => $sessions
                    ->filter(fn (ClassSession $session): bool => $session->status === ClassSessionStatus::Cancelled)
                    ->count(),
                'attendance_rate' => $countable->isEmpty()
                    // Not zero. No sessions yet is an absence of data, and a
                    // teacher who has taught nothing has not failed to turn up.
                    ? null
                    : (int) round($delivered->count() / $countable->count() * 100),
                // pluck()->min() rather than sortBy()->first(): the earliest
                // value is what is wanted, and asking for it directly leaves no
                // nullable model to unwrap.
                'first_session_at' => $delivered->pluck('starts_at')->min() ?? $profile->first_session_at,
            ])->save();
        });
    }

    /**
     * Distinct students who ATTENDED a delivered session of this teacher.
     *
     * ⚠️ THE HOST IS EXCLUDED, AND THEY HAVE AN ATTENDANCE ROW ON PURPOSE.
     * `CloseClassSession` judges delivery — the teacher's own pay — from the
     * teacher's attendance row, so it is not an accident to be cleaned up. Left
     * in, every teacher counts themselves among their own students, and the
     * public figure is off by one for everybody. `Attendance::excludingHost()`
     * expresses the same rule per session; here the host is one person across the
     * whole set, so the id is compared directly.
     *
     * ⚠️ AND `Excused` DOES NOT COUNT. It counts as attendance for the UNLOCK
     * GATE, where the question is whether to hold an absence against a student —
     * a decision about entitlement. Here the question is «how many students has
     * this teacher actually taught», published to visitors as a trust claim
     * (FR-038), and somebody excused was not in the lesson. Two questions, two
     * answers; borrowing the gate's would inflate a public number.
     *
     * @param  list<int>  $deliveredSessionIds
     */
    private function studentsTaught(TeacherProfile $profile, array $deliveredSessionIds): int
    {
        if ($deliveredSessionIds === []) {
            return 0;
        }

        return Attendance::query()
            ->whereIn('class_session_id', $deliveredSessionIds)
            ->whereIn('status', [AttendanceStatus::Present->value, AttendanceStatus::Late->value])
            // The host is never null on a profile — the column is NOT NULL — so
            // this is an unconditional exclusion, not a defensive branch.
            ->where('student_user_id', '!=', $profile->user_id)
            // One person taught in twenty sessions is one student, not twenty.
            ->distinct()
            ->count('student_user_id');
    }
}
