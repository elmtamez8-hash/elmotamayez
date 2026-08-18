<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\BroadcastProviderResolver;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Media\Actions\CompleteMediaUpload;
use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Exceptions\PermanentIngestFailure;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Hands a finished recording to the video pipeline from spec 004.
 *
 * "Not ready yet" is the expected answer the first time it runs after every
 * session, so a null artifact reschedules rather than failing — the recording is
 * still being assembled at the provider. Only a real failure counts against the
 * attempt limit.
 *
 * After the limit the teacher is told, and manual upload stays open (FR-031). A
 * pipeline that gives up quietly leaves a class waiting for a video nobody knows
 * is not coming.
 */
class IngestSessionRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $classSessionId,
    ) {}

    public function handle(
        WorkspaceContext $context,
        BroadcastProviderResolver $providers,
        MediaProviderInterface $video,
        SessionSettings $settings,
        CompleteMediaUpload $complete,
        DispatchNotification $notify,
    ): void {
        $session = ClassSession::query()->withoutWorkspaceScope()->find($this->classSessionId);

        if ($session === null || $session->recording_status === 'published') {
            return;
        }

        /*
         * ⚠️ RESOLVED FROM THE SESSION, NOT FROM THE CONFIG — the same defect 019
         * fixed for `media_assets.provider`, one module over.
         *
         * `broadcast_provider` was written when the room opened and read by
         * nothing. So flipping the platform to a provider that does not record,
         * while yesterday's recordings are still in flight, made this return here
         * having written NOTHING: the egress file sits in our own bucket with
         * nobody left to ask for it, `PackageCompletion` releases the teacher's fee
         * against it, and the seat holders are never told — the notification lives
         * below this return.
         */
        $broadcast = $providers->for($session);

        if (! $broadcast->capabilities()->recording) {
            // Nothing to wait for. Recording every session at a provider that
            // cannot record is not a failure to report over and over.
            return;
        }

        $context->forWorkspace((int) $session->workspace_id, function () use (
            $session, $broadcast, $video, $settings, $complete, $notify
        ): void {
            /*
             * ⚠️ ALREADY HANDED OVER ⇒ ASK, NEVER DELIVER AGAIN.
             *
             * Since 019 the delivery is a fetch request the provider acts on
             * later, so this job runs again while the previous hand-off is still
             * in flight — and `videos/fetch` creates a NEW video on every call
             * (019 research §R3). A second delivery is therefore a second video
             * for one lesson: paid for monthly, referenced by nothing, and
             * invisible without going to look. Re-asking is free; re-delivering
             * is not.
             */
            $existing = $session->media_asset_id === null
                ? null
                : MediaAsset::query()->withoutWorkspaceScope()->find($session->media_asset_id);

            /*
             * ⚠️ EVERY PROVIDER CALL IS INSIDE THIS `try`, AND TWO OF THEM USED TO
             * SIT OUTSIDE IT — WHICH TURNED AN OUTAGE INTO A PERMANENT LOSS.
             *
             * `recording()` is a live HTTP call (`listEgress`) and throws on any
             * network fault; `settle()` reaches the media provider through
             * `CompleteMediaUpload`. Both stood above the `try`, and Horizon runs
             * this queue at `tries: 1` — so one refused connection killed the job
             * BEFORE a single column was written. `recording_status` stayed NULL,
             * and nothing sweeps NULL: the column is nullable with no default and
             * has no initial writer outside these jobs, so the session sat there
             * for ever while `PackageCompletion` held the teacher's fee for a
             * lesson that was actually taught. The only trace was a row in
             * `failed_jobs`.
             *
             * Inside the `try`, the same outage writes `pending` and spends an
             * attempt — a state the sweep comes back for.
             */
            try {
                if ($existing !== null) {
                    $this->settle($session, $existing, $settings, $complete, $notify);

                    return;
                }

                $artifact = $broadcast->recording($session);

                if ($artifact === null) {
                    $this->giveUpOrRetry($session, $settings, $notify, 'التسجيل لم يكتمل بعد.');

                    return;
                }

                $asset = new MediaAsset([
                    'workspace_id' => $session->workspace_id,
                    // Polymorphic since 004, which anticipated exactly this.
                    'owner_type' => ClassSession::class,
                    'owner_id' => $session->getKey(),
                    'provider' => $video->identifier(),
                    /*
                     * ⚠️ NULL, AND THAT IS THE POINT OF THIS PHASE.
                     *
                     * It used to be the path this job had just downloaded to.
                     * Where the file lives is the PROVIDER's answer now, and it
                     * has not been asked yet at this line — `ingestFromUrl`
                     * writes the id the fetch returns, and a delivery whose
                     * response never came back leaves this null on purpose: the
                     * id is then recovered from the title, and until either
                     * happens the asset is legitimately mid-ingest, neither
                     * ready nor failed (SC-014).
                     */
                    'provider_asset_id' => null,
                    // Stated, not left to the column default: a model built with
                    // `new` carries no default until it round-trips, and
                    // CompleteMediaUpload reads the kind to pick its mime list.
                    'kind' => MediaKind::Video,
                    'role' => MediaRole::Primary,
                    'status' => MediaAssetStatus::Processing,
                    'original_filename' => 'session-'.$session->uuid.'.mp4',
                    'duration_seconds' => $artifact->durationSeconds,
                ]);
                $asset->save();

                /*
                 * ⚠️ THE GUARD AGAINST A SECOND DELIVERY IS THIS UPDATE, AND IT USED
                 * TO BE A READ FOLLOWED BY A WRITE.
                 *
                 * `media_asset_id` was checked at the top of this closure and
                 * written here — the textbook race, and this session has two
                 * senders: `SessionCompleted` fires once and the sweep re-dispatches
                 * every fifteen minutes, so two runners overlapping is ordinary, not
                 * exotic. Both would read null, both would call `videos/fetch`, and
                 * every call to it CREATES A VIDEO: a second one, paid for monthly,
                 * referenced by nothing and invisible without going to look.
                 *
                 * One conditional UPDATE is both the check and the claim, which is
                 * the idiom this repository already uses for seats, for
                 * `captured_order_id` and in `StructureVersion::claim()`. Never
                 * `lockForUpdate()`: it is a no-op on SQLite, so the test would pass
                 * locally and prove nothing about the MySQL this ships to.
                 *
                 * The asset row is created BEFORE the claim because the claim needs
                 * its id — and that is safe precisely because nothing has been
                 * delivered yet. A loser deletes its own row, having spent one local
                 * INSERT and not one provider call.
                 */
                $claimed = ClassSession::query()
                    ->withoutWorkspaceScope()
                    ->whereKey($session->getKey())
                    ->whereNull('media_asset_id')
                    ->update([
                        'media_asset_id' => $asset->getKey(),
                        'recording_status' => 'ingesting',
                    ]);

                if ($claimed === 0) {
                    $asset->delete();
                    $session->refresh();

                    $winner = $session->media_asset_id === null
                        ? null
                        : MediaAsset::query()->withoutWorkspaceScope()->find($session->media_asset_id);

                    // Ask about the winner's asset rather than returning blind: this
                    // pass is still a pass, and the other runner may already have
                    // delivered.
                    if ($winner !== null) {
                        $this->settle($session, $winner, $settings, $complete, $notify);
                    }

                    return;
                }

                // The claim was made by a query, so the in-memory model still
                // carries the old columns — and `settle()` writes through it.
                $session->refresh();

                /*
                | ⚠️ THIS LINE IS THE WHOLE OF SPEC 019 IN THIS FILE.
                |
                | It used to be forty lines that streamed the recording onto our own
                | disk and then handed the path over — a gigabyte per lesson through
                | this worker, which is the load self-hosting was rejected for. That
                | code was not deleted: it MOVED to LocalMediaProvider, which still
                | does exactly it, because for a provider with no remote fetch the
                | download is the only way. The job stopped choosing.
                */
                $video->ingestFromUrl($asset, $artifact->downloadUrl);

                $this->settle($session, $asset, $settings, $complete, $notify);
            } catch (PermanentIngestFailure $e) {
                // A malformed request or a wrong key. Five more attempts over an
                // hour tell the teacher nothing they cannot be told now.
                report($e);

                $this->giveUpOrRetry($session, $settings, $notify, $e->getMessage(), permanent: true);
            } catch (Throwable $e) {
                // The reason is carried into the notification, and logged: a
                // silent retry loop tells nobody why the fifth attempt failed.
                report($e);

                $this->giveUpOrRetry($session, $settings, $notify, $e->getMessage());
            }
        });
    }

    /**
     * Ask the provider where the asset got to, and act on the answer.
     *
     * Three outcomes, and the middle one is the one that did not exist before
     * 019: ready (the listener publishes the lesson), failed, or **still coming**.
     *
     * ⚠️ AN ATTEMPT IS SPENT ON "still encoding", DELIBERATELY. It is the same
     * answer "not finished yet" has always been, and the budget is what stops an
     * unbounded wait with a teacher's fee held behind it. If a provider's encode
     * is genuinely slower than the budget, the fix is NOT a bigger number here:
     * ReconcileAssetStatus keeps asking after this job has given up, and a late
     * Ready still fires MediaAssetReady, whose listener publishes the lesson and
     * overwrites `failed` with `published`. The self-heal is the design, not luck.
     *
     * ⚠️ AND IT IS BOUNDED, which this said nothing about until the poll was given
     * a ceiling: `media.reconcile_ceiling_hours` (48 by default). Past it the asset
     * is written failed with a reason and nothing asks again — an unbounded poll
     * was two provider calls per stuck asset every five minutes, for ever. So the
     * self-heal covers a slow encode, not an abandoned one.
     */
    private function settle(
        ClassSession $session,
        MediaAsset $asset,
        SessionSettings $settings,
        CompleteMediaUpload $complete,
        DispatchNotification $notify,
    ): void {
        // Settles the asset and, when it lands Ready, fires MediaAssetReady —
        // which the listener turns into a lesson and writes recording_status.
        $complete->handle($asset);

        if ($asset->status === MediaAssetStatus::Ready) {
            return;
        }

        // Processing or Failed — either way this session is not done, and the
        // sweep only comes back for 'pending', which giveUpOrRetry writes.
        $this->giveUpOrRetry(
            $session,
            $settings,
            $notify,
            $asset->failure_reason ?? 'التسجيل لم يكتمل عند مزوّد الوسائط بعد.',
        );
    }

    /**
     * @param  bool  $permanent  Skip the remaining attempts: the provider refused in
     *                           a way a retry cannot change.
     */
    private function giveUpOrRetry(
        ClassSession $session,
        SessionSettings $settings,
        DispatchNotification $notify,
        string $reason,
        bool $permanent = false,
    ): void {
        $limit = $settings->recordingMaxAttempts();

        /*
         * ⚠️ INCREMENTED IN THE DATABASE, NOT IN PHP. `$session->recording_attempts + 1`
         * is a read followed by a write, and two runners for one session — the
         * completion event and a sweep pass overlapping — both read the same number
         * and both write the same number. The budget then never advances while both
         * keep failing, which is an unbounded retry loop wearing a counter.
         */
        $query = ClassSession::query()->withoutWorkspaceScope()->whereKey($session->getKey());

        // A permanent refusal spends the whole budget at once: five more attempts
        // over an hour tell the teacher nothing they cannot be told now.
        $permanent
            ? $query->update(['recording_attempts' => $limit])
            : $query->increment('recording_attempts');

        $session->refresh();
        $attempts = (int) $session->recording_attempts;

        $session->forceFill([
            'recording_status' => $attempts >= $limit ? 'failed' : 'pending',
        ])->save();

        if ($attempts < $limit) {
            return;
        }

        $teacher = $session->teacherProfile?->user;

        if ($teacher !== null) {
            // Named by type, never by channel — the channel is the recipient's
            // choice, and ProviderAgnosticTest fails the build if an Action names one.
            $notify->handle(new NotificationRequest(
                recipient: $teacher,
                type: NotificationType::SessionRecordingFailed,
                variables: ['title' => $session->title, 'reason' => $reason],
                actionUrl: '/manage/sessions/'.$session->uuid,
                workspaceId: (int) $session->workspace_id,
            ));
        }

        $this->notifySeatHolders($session, $notify);
    }

    /**
     * The people still waiting once nobody else is (019 FR-009أ).
     *
     * ⚠️ THIS IS WHY THE SILENCE WAS POSSIBLE. `failed` is the state that RELEASES
     * the teacher's held fee — correctly, the lesson was taught — so at the moment
     * this runs, the last person who had a financial reason to ask about the
     * recording has been paid and has stopped asking. The seat holder is the only
     * one left, and their only recourse was to guess (research §R10).
     */
    private function notifySeatHolders(ClassSession $session, DispatchNotification $notify): void
    {
        $holders = User::query()
            ->whereIn('id', $session->bookings()
                ->where('status', BookingStatus::Booked)
                ->pluck('student_user_id'))
            ->get();

        foreach ($holders as $holder) {
            $notify->handle(new NotificationRequest(
                recipient: $holder,
                type: NotificationType::SessionRecordingUnavailable,
                // No provider reason here: it names a system the student has no
                // access to and cannot act on.
                variables: ['title' => $session->title],
                actionUrl: '/sessions/'.$session->uuid,
                workspaceId: (int) $session->workspace_id,
            ));
        }
    }
}
