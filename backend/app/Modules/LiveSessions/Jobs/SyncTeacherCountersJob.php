<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fills the four counters teacher_profiles has carried since spec 001 with
 * nothing writing to them.
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
                ->get(['status', 'delivered_at', 'starts_at']);

            // Cancelled and suspended sessions are excluded from both sides of
            // the ratio (FR-026): a holiday is not a failure to teach.
            $countable = $sessions->filter(
                fn (ClassSession $session): bool => $session->status->countsTowardsCounters()
                    || $session->status === ClassSessionStatus::Interrupted,
            );

            $delivered = $countable->filter(fn (ClassSession $session): bool => $session->delivered_at !== null);

            $profile->forceFill([
                'completed_sessions_count' => $delivered->count(),
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
}
