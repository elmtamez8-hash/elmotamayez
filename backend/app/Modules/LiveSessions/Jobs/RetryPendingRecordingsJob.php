<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The sweep that makes "pending" a state and not a grave.
 *
 * ⚠️ IT DID NOT EXIST, AND ITS ABSENCE WAS INVISIBLE UNTIL NOW.
 * `IngestSessionRecordingJob::giveUpOrRetry()` increments the counter, writes
 * `recording_status = 'pending'` — and RETURNS. No `release()`, no second
 * dispatch. The only sender was `SessionCompleted`, which fires once. So the
 * first "not finished yet" was also the last attempt, and the session stayed
 * pending for ever with `recording_attempts` frozen at 1.
 *
 * It was silent because `NullBroadcastProvider` declares `recording: false`, so
 * the job returned before reaching that branch. 017 is what switches the branch
 * on — which is why the sweep ships with it and not later.
 *
 * ⚠️ AND THE PRICE IS NOT A STUCK BADGE, IT IS A TEACHER'S PAY.
 * `Settlement\Support\PackageCompletion` reads the same `recording_status` and
 * holds a unit back while it is neither published nor failed. An eternal
 * `pending` withholds the fee for a lesson that was actually taught.
 *
 * This job sets no workspace at all: it only dispatches, and the ingest job
 * enters each workspace through `forWorkspace()` itself (Constitution §I).
 * `CounterJobIsolationTest` greps this directory for the forbidden call — and
 * greps it as a STRING, so even naming it in a comment fails the build. That is
 * the guard working, not overreaching.
 */
class RetryPendingRecordingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How far back to look. Older than this and a manual upload is the answer. */
    private const WINDOW_HOURS = 48;

    public function handle(SessionSettings $settings): void
    {
        ClassSession::query()
            // Platform-wide by design: a recording is stuck in whichever
            // workspace it belongs to, and no workspace is current in a
            // scheduled job. Every dispatched job re-enters its own.
            ->withoutWorkspaceScope()
            ->where('recording_status', 'pending')
            // The limit still decides when to stop. This sweep resends; it never
            // grants an extra attempt.
            ->where('recording_attempts', '<', $settings->recordingMaxAttempts())
            ->where('room_closed_at', '>=', now()->subHours(self::WINDOW_HOURS))
            // chunkById, not chunk: the predicate shrinks under an OFFSET walk as
            // rows leave 'pending', so every page after the first would skip as
            // many sessions as the previous page fixed — and report success.
            ->chunkById(100, function (Collection $sessions): void {
                foreach ($sessions as $session) {
                    IngestSessionRecordingJob::dispatch((int) $session->getKey());
                }
            });

        $this->alertPlatformOnRepeatedFailure($settings);
    }

    /**
     * One signal that says "look at the provider, not at the sessions" (019 FR-009ب).
     *
     * ⚠️ SEPARATE FROM THE PER-TEACHER NOTIFICATION, AND THAT SEPARATION IS THE
     * POINT. Forty failures send forty teachers a message about their own lesson;
     * not one of those messages shows anybody that there is a single cause. The
     * outage is then found by whoever happens to add them up, which is nobody, at
     * night, when the provider's keys were rotated.
     *
     * ⚠️ AND IT IS A LOG ALERT, NOT A NOTIFICATION. `DispatchNotification` needs a
     * recipient and a workspace, and "the video provider is refusing everything" is
     * neither one teacher's news nor one workspace's. This is where an operator
     * already watches — and the repetition every fifteen minutes while it is still
     * true is the signal, not noise.
     *
     * Platform-wide, deliberately: one provider serves every workspace, so a count
     * inside any single one of them would be under the threshold while the platform
     * as a whole was down.
     */
    private function alertPlatformOnRepeatedFailure(SessionSettings $settings): void
    {
        $threshold = $settings->recordingFailureAlertThreshold();

        if ($threshold <= 0) {
            return;
        }

        $failures = ClassSession::query()
            ->withoutWorkspaceScope()
            ->where('recording_status', 'failed')
            ->where('room_closed_at', '>=', now()->subHours($settings->recordingFailureAlertWindowHours()))
            ->count();

        if ($failures < $threshold) {
            return;
        }

        Log::alert('عددٌ غيرُ معتاد من تسجيلات الحصص الفاشلة — راجعْ مزوّد الوسائط.', [
            'failed_recordings' => $failures,
            'window_hours' => $settings->recordingFailureAlertWindowHours(),
            'threshold' => $threshold,
        ]);
    }
}
