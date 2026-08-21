<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Jobs;

use App\Modules\Compliance\Actions\ExecuteDataExport;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Support\ComplianceSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Run one data request in the background (FR-016 · SC-014).
 *
 * ⚠️ IT TAKES AN ID AND NOTHING ELSE. Laravel serialises a job's constructor
 * arguments, the queue is Redis, and `failed_jobs.payload` keeps the same blob
 * afterwards — in a table nothing prunes. Passing the export payload, or the
 * subject model, would put a copy of everything the platform knows about a minor
 * into two places nobody thinks of as storage. `SerializesModels` would even make
 * a `DataRequest` argument look harmless; the id is the only shape that is.
 *
 * ⚠️ AND THE REQUEST IS CLAIMED BY A CONDITIONAL UPDATE, WHICH IS BOTH THE CHECK
 * AND THE CLAIM. `SessionCompleted`-style single dispatch is not the situation
 * here: `RetryStalledDataRequestsJob` re-sends every ten minutes, so two runners
 * meeting on one request is ordinary rather than exotic. A read followed by a write
 * lets both pass, and two archives of one child's entire record are then generated
 * and signed. `UPDATE … WHERE status = 'pending'` — zero rows means somebody else
 * has it. Never `lockForUpdate()`: it is a no-op on SQLite, so a test written
 * around it passes locally and proves nothing about the MySQL this ships to.
 */
class FulfilDataRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ⚠️ ONE ATTEMPT, AND THE SWEEP IS THE RETRY. A retried job would re-enter a
     * request already marked `processing` and be refused by its own claim, so the
     * retry has to come from outside — which is also the only place that can tell a
     * killed worker from a slow one.
     */
    public int $tries = 1;

    /**
     * Longer than any other job in the product, deliberately: this walks thirteen
     * modules over every row a person owns, and `supervisor-compliance` exists so
     * that it does not share a timeout with a notification.
     */
    public int $timeout = 900;

    public function __construct(private readonly int $dataRequestId)
    {
        /*
        | ⚠️ THE QUEUE IS SET HERE, NOT AS A PROPERTY. `Queueable` already declares
        | `$queue`, and PHP refuses a redeclaration whose default differs — the
        | class fatals at composition time, before any test runs. `onQueue()` is
        | the sanctioned form and is also the one a caller cannot silently forget,
        | since it travels with the job rather than with the dispatch.
        |
        | `compliance` has its own Horizon supervisor: this job runs for minutes
        | and `supervisor-1` kills at sixty seconds. A supervisor named only in
        | `defaults` would be a queue with no worker — see `config/horizon.php`.
        */
        $this->onQueue('compliance');
    }

    public function handle(ExecuteDataExport $export): void
    {
        $now = now();

        $request = DataRequest::query()->find($this->dataRequestId);

        if ($request === null) {
            return;
        }

        /*
        | ⚠️ THE TYPE IS CHECKED BEFORE THE CLAIM, AND THE OTHER ORDER IS AN
        | INFINITE LOOP. Erasure is a different Action with a different
        | reversibility and it lands with US4 — but claiming a request and then
        | returning takes a lock nothing releases: the sweep finds it stale, resets
        | it to pending, re-dispatches, it claims and returns again, every thirty
        | minutes for ever, logging a stall each time while the person's request
        | never moves and is never refused. `StoreDataRequestRequest` refuses the
        | type at the door as well; this is the second half, because the Action is
        | reachable from a seeder and from the panel with no form in front of it.
        */
        if ($request->type === DataRequestType::Erasure) {
            return;
        }

        /*
        | The claim. `last_attempt_at` is stamped inside the SAME statement, because
        | a second write is a second round trip in which a competing worker claims
        | it too — and `updated_at` cannot serve, since it moves for every unrelated
        | write to the row. Precedent: `recording_attempted_at`.
        */
        $claimed = DataRequest::query()
            ->where('id', $this->dataRequestId)
            ->where('status', DataRequestStatus::Pending->value)
            ->update([
                'status' => DataRequestStatus::Processing->value,
                'last_attempt_at' => $now,
                'updated_at' => $now,
            ]);

        if ($claimed === 0) {
            return;
        }

        $request->refresh();

        try {
            $result = $export->handle($request);

            $request->forceFill([
                'status' => DataRequestStatus::Completed->value,
                'export_path' => $result['path'],
                'export_expires_at' => $now->copy()->addHours(ComplianceSettings::exportTtlHours()),
                'completed_at' => $now,
                // The lock is released the moment the request closes: the person
                // may open a new one, and NULL never collides with NULL.
                'open_key' => null,
            ])->save();
        } catch (Throwable $exception) {
            /*
            | ⚠️ IT IS LEFT IN `processing`, AND RESETTING IT TO `pending` HERE WAS A
            | DEAD END. `RetryStalledDataRequestsJob` sweeps `processing` and nothing
            | else — the state a killed worker leaves — so a request helpfully
            | returned to `pending` is dispatched by NOTHING, ever: the initial
            | dispatch already fired and `tries: 1` means the queue will not retry.
            | It would sit there while `due_at` passed, silently. The
            | `recording_status = 'ingesting'` family, reached by trying to be tidy.
            |
            | And not `refused` either: that is a legal answer with a reason
            | attached, and writing it over a broken query tells a family their
            | request was declined when in fact ours failed.
            */
            Log::error('compliance.request.failed', [
                'request_id' => $this->dataRequestId,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
