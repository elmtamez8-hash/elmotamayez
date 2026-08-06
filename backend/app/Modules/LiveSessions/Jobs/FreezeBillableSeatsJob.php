<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes down how many seats were held when the cancellation window shut.
 *
 * The number answers a question about a MOMENT THAT HAS PASSED, so it is stored
 * once and never recomputed (FR-060). Deriving it later from live bookings would
 * give a different answer after every late cancellation — and the wrong one,
 * because a late cancellation is still charged.
 *
 * Idempotent: a queue may run a job twice, and the second run must not move a
 * number the first one settled.
 */
class FreezeBillableSeatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $classSessionId,
    ) {}

    public function handle(WorkspaceContext $context): void
    {
        $session = ClassSession::query()->withoutWorkspaceScope()->find($this->classSessionId);

        if ($session === null || $session->seats_frozen_at !== null) {
            return;
        }

        if ($session->status === ClassSessionStatus::Cancelled) {
            return;
        }

        // forWorkspace, never set(): WorkspaceContext is an application-wide
        // singleton that caches its answer, so a worker that sets it leaks this
        // workspace into whatever it handles next.
        $context->forWorkspace((int) $session->workspace_id, function () use ($session): void {
            $billable = $session->bookings()
                ->whereIn('status', [BookingStatus::Booked, BookingStatus::CancelledLate])
                ->count();

            $session->forceFill([
                'billable_seats' => $billable,
                'seats_frozen_at' => now(),
                // Nobody booked. Not an error and not a cancellation — a teacher
                // who turned up for an empty room is an operational fact worth a
                // human look, not silence (FR-061).
                'interruption_note' => $billable === 0 ? 'zero_attendance' : $session->interruption_note,
            ])->save();
        });
    }
}
