<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Jobs;

use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Support\ComplianceSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The sweep that makes `processing` a state and not a grave (SC-021).
 *
 * ⚠️ NOTHING ELSE SWEEPS THIS STATE, AND `FulfilDataRequestJob` RUNS WITH
 * `tries: 1`. So a worker killed mid-export — a deploy, an OOM, a restart — leaves
 * the request `processing` for ever: `due_at` passes, the legal deadline is missed,
 * and nobody is told, because nothing is broken enough to log. This is the
 * `recording_status = 'ingesting'` family exactly — a state written just before a
 * killable call, and a sweep that asked about a different value.
 *
 * ⚠️ AND IT RETURNS THE REQUEST TO `pending` BEFORE RE-DISPATCHING, because the
 * claim in `FulfilDataRequestJob` is `WHERE status = 'pending'`. Re-sending without
 * resetting would put a job on the queue that refuses itself on arrival, for ever,
 * every ten minutes — a sweep that reports success and moves nothing.
 *
 * The reset is itself a conditional UPDATE on `(status, last_attempt_at)`, so two
 * overlapping sweeps cannot both revive one request: the second matches zero rows.
 */
class RetryStalledDataRequestsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A ceiling, so one bad row cannot make this run unbounded. */
    private const BATCH = 50;

    public function handle(): void
    {
        $cutoff = now()->subMinutes(ComplianceSettings::stalledAfterMinutes());

        $stalled = DataRequest::query()
            ->where('status', DataRequestStatus::Processing->value)
            // A request claimed but never stamped is impossible — the claim writes
            // both in one statement — but a null here would silently escape a
            // `where('<', …)` comparison, so it is named rather than assumed away.
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<', $cutoff);
            })
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($stalled as $request) {
            $revived = DataRequest::query()
                ->where('id', $request->getKey())
                ->where('status', DataRequestStatus::Processing->value)
                ->update([
                    'status' => DataRequestStatus::Pending->value,
                    'updated_at' => now(),
                ]);

            if ($revived === 0) {
                continue;
            }

            Log::warning('compliance.request.stalled', [
                'request_id' => $request->getKey(),
                'last_attempt_at' => $request->last_attempt_at?->toIso8601String(),
            ]);

            FulfilDataRequestJob::dispatch((int) $request->getKey());
        }
    }
}
