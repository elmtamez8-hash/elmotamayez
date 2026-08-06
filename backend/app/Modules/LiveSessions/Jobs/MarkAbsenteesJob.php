<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Modules\LiveSessions\Enums\AttendanceSource;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Marks the seats that never sent a heartbeat, at the threshold.
 *
 * Dispatched with a delay when the room opens, so it runs at start + half the
 * session and not a moment later. FR-021ب and SC-021 are about WHEN this
 * happens, not only what it writes: a nightly sweep would produce the same rows
 * and still fail the requirement, and a per-minute scan would sweep every live
 * session forever to serve one instant each.
 *
 * Idempotent, and it only ever writes over an automatic Absent — a teacher who
 * has already marked someone present by hand must not be undone by a job.
 */
class MarkAbsenteesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $classSessionId,
    ) {}

    public function handle(WorkspaceContext $context): void
    {
        $session = ClassSession::query()->withoutWorkspaceScope()->find($this->classSessionId);

        if ($session === null || $session->status === ClassSessionStatus::Cancelled) {
            return;
        }

        $context->forWorkspace((int) $session->workspace_id, function () use ($session): void {
            // FR-041: inside a freeze nothing is counted absent. The holiday is
            // not the student's absence.
            $frozen = FreezePeriod::query()
                ->covering($session->starts_at)
                ->exists();

            if ($frozen) {
                return;
            }

            $seats = $session->bookings()
                ->whereIn('status', [BookingStatus::Booked, BookingStatus::CancelledLate])
                ->get();

            foreach ($seats as $seat) {
                Attendance::query()->firstOrCreate(
                    [
                        'class_session_id' => $session->getKey(),
                        'student_user_id' => $seat->student_user_id,
                    ],
                    [
                        'workspace_id' => $session->workspace_id,
                        'status' => AttendanceStatus::Absent,
                        'auto_status' => AttendanceStatus::Absent,
                        'source' => AttendanceSource::Automatic,
                        'stay_seconds' => 0,
                    ],
                );
            }
        });
    }
}
