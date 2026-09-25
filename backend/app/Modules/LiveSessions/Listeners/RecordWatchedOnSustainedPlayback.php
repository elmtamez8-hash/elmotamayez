<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Actions\RecordRecordingWatched;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Events\PlaybackSustained;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * Turns «this viewing ran long enough» into «watched the recording later» on the
 * register (FR-021د).
 *
 * The one production caller of {@see RecordRecordingWatched}. Until it existed
 * the Action was reached only by a test, so the register's «شاهد التسجيل لاحقاً»
 * could never appear for anybody — a column with a reader and no writer.
 *
 * Claims only what is a session's recording: the PRIMARY file of a lesson that
 * carries a `class_session_id`. An attachment on that lesson is not the hour,
 * and every other video in the product is somebody else's business.
 *
 * Queued, and after commit: it runs off the renewal the watermark depends on,
 * and a throw here must never be the reason a student's player stops. It moves
 * no status and no money — the Action's own contract.
 */
class RecordWatchedOnSustainedPlayback implements ShouldQueueAfterCommit
{
    public function __construct(private readonly RecordRecordingWatched $record) {}

    public function handle(PlaybackSustained $event): void
    {
        $asset = MediaAsset::withoutWorkspaceScope()->find($event->mediaAssetId);

        if ($asset === null
            || $asset->owner_type !== Lesson::class
            || $asset->role !== MediaRole::Primary) {
            return;
        }

        // Unscoped by primary key: the viewer's context is whichever teacher they
        // last visited, and the scope would read null for a stamped student.
        $sessionId = Lesson::withoutWorkspaceScope()->whereKey($asset->owner_id)->value('class_session_id');

        if ($sessionId === null) {
            return;
        }

        $session = ClassSession::withoutWorkspaceScope()->whereKey((int) $sessionId)->first();
        $viewer = User::query()->find($event->userId);

        if ($session === null || $viewer === null) {
            return;
        }

        $this->record->handle($session, $viewer);
    }
}
