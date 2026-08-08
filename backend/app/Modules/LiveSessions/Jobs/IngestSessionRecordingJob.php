<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Media\Actions\CompleteMediaUpload;
use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
        BroadcastProviderInterface $broadcast,
        MediaProviderInterface $video,
        SessionSettings $settings,
        CompleteMediaUpload $complete,
        DispatchNotification $notify,
    ): void {
        $session = ClassSession::query()->withoutWorkspaceScope()->find($this->classSessionId);

        if ($session === null || $session->recording_status === 'published') {
            return;
        }

        if (! $broadcast->capabilities()->recording) {
            // Nothing to wait for. Recording every session at a provider that
            // cannot record is not a failure to report over and over.
            return;
        }

        $context->forWorkspace((int) $session->workspace_id, function () use (
            $session, $broadcast, $video, $settings, $complete, $notify
        ): void {
            $artifact = $broadcast->recording($session);

            if ($artifact === null) {
                $this->giveUpOrRetry($session, $settings, $notify, 'التسجيل لم يكتمل بعد.');

                return;
            }

            try {
                $path = 'media/sessions/'.$session->uuid.'.mp4';

                // Buffered rather than streamed, deliberately for now: the size
                // ceiling is already enforced by platform settings, and a real
                // provider will hand us a URL we can stream when one is signed.
                $response = Http::timeout(120)->get($artifact->downloadUrl);

                if (! $response->successful()) {
                    throw new \RuntimeException('تعذّر تنزيل التسجيل.');
                }

                Storage::disk('local')->put($path, $response->body());

                $asset = new MediaAsset([
                    'workspace_id' => $session->workspace_id,
                    // Polymorphic since 004, which anticipated exactly this.
                    'owner_type' => ClassSession::class,
                    'owner_id' => $session->getKey(),
                    'provider' => $video->identifier(),
                    'provider_asset_id' => $path,
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

                $session->forceFill([
                    'media_asset_id' => $asset->getKey(),
                    'recording_status' => 'ingesting',
                ])->save();

                // Settles the asset and, when it lands Ready, fires
                // MediaAssetReady — which the listener turns into a lesson.
                $complete->handle($asset);
            } catch (Throwable $e) {
                // The reason is carried into the notification, and logged: a
                // silent retry loop tells nobody why the fifth attempt failed.
                report($e);

                $this->giveUpOrRetry($session, $settings, $notify, $e->getMessage());
            }
        });
    }

    private function giveUpOrRetry(
        ClassSession $session,
        SessionSettings $settings,
        DispatchNotification $notify,
        string $reason,
    ): void {
        $attempts = $session->recording_attempts + 1;

        $session->forceFill([
            'recording_attempts' => $attempts,
            'recording_status' => $attempts >= $settings->recordingMaxAttempts() ? 'failed' : 'pending',
        ])->save();

        if ($attempts < $settings->recordingMaxAttempts()) {
            return;
        }

        $teacher = $session->teacherProfile?->user;

        if ($teacher === null) {
            return;
        }

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
}
