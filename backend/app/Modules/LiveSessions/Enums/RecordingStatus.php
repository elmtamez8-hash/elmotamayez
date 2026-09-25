<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Enums;

/**
 * Where a session's recording is on its way into the course tree
 * (`class_sessions.recording_status`, nullable).
 *
 * Every case has a writer and a reader, and that is the point of listing them in
 * one place — `ClassSessionStatus::Interrupted` was an enum value with three
 * readers and no writer (docs/gotchas/live-sessions.md):
 *
 * - `Ingesting` — `IngestSessionRecordingJob`, just before the hand-off.
 * - `Pending`   — `IngestSessionRecordingJob`, «not finished yet» / a retryable
 *                 failure with attempts left.
 * - `Failed`    — `IngestSessionRecordingJob`, the attempt budget spent.
 * - `NoCourse`  — `PublishRecordingAsLesson`, a session with no course to
 *                 publish into.
 * - `Published` — `PublishRecordingAsLesson`, the lesson exists.
 *
 * ⚠️ NULL IS A FOURTH STATE, NOT «NOTHING EXPECTED». The column has no default
 * and no writer before the ingest job, so NULL is also what a job that died
 * before its first write leaves behind — one of the THREE stuck values
 * (`null`, `ingesting`, `pending`) `RetryPendingRecordingsJob` sweeps
 * (docs/gotchas/media.md).
 */
enum RecordingStatus: string
{
    case Pending = 'pending';
    case Ingesting = 'ingesting';
    case Published = 'published';
    case Failed = 'failed';
    case NoCourse = 'no_course';

    /**
     * Nothing more will happen to this recording: delivered, or provably never
     * coming. This is what releases a withheld teacher fee
     * (`Settlement\Support\PackageCompletion`) — null, `pending` and `ingesting`
     * are still on their way and hold it.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Published, self::Failed, self::NoCourse => true,
            self::Pending, self::Ingesting => false,
        };
    }

    /** Still being fetched or transcoded — a real state, distinct from «never». */
    public function isProcessing(): bool
    {
        return ! $this->isTerminal();
    }
}
