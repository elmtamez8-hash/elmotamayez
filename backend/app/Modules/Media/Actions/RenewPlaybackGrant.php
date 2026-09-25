<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Media\Events\PlaybackSustained;
use App\Modules\Media\Models\PlaybackGrant;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Actions\Action;
use App\Shared\Scopes\WorkspaceScope;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Extends a grant, and records where the viewer has got to.
 *
 * Called by the watermark component, which is the design's load-bearing detail:
 * the overlay is not merely watched for, it is the thing that keeps playback
 * alive. Remove it from the DOM and renewal stops, the grant lapses, and the
 * next range request is refused — the decision sits on the server, where it
 * cannot be edited away.
 *
 * Position is saved on the same call rather than a separate endpoint: the call
 * already happens once a minute, and a second one at the same cadence would
 * double the traffic for nothing.
 */
class RenewPlaybackGrant extends Action
{
    /**
     * @throws DomainException when the grant has been renewed too many times
     */
    public function handle(PlaybackGrant $grant, ?int $positionSeconds = null): PlaybackGrant
    {
        $max = (int) PlatformSettings::get('media.max_renewals', 480);

        if ($grant->renewed_count >= $max) {
            // Bounds a single viewing session. Without it, one grant kept warm by
            // a script never expires.
            throw new DomainException('انتهت مدة جلسة المشاهدة. أعد فتح الدرس.');
        }

        $ttl = (int) PlatformSettings::get('media.grant_ttl_seconds', 300);

        // Read BEFORE the write below moves it: the previous renewal is one edge
        // of the interval this call covers, and the crossing is judged on it.
        $previousSeenAt = $grant->last_seen_at ?? $grant->created_at;

        $grant->forceFill([
            'expires_at' => now()->addSeconds($ttl),
            'renewed_count' => $grant->renewed_count + 1,
            'last_seen_at' => now(),
        ])->save();

        if ($positionSeconds !== null) {
            $this->rememberPosition($grant, $positionSeconds);
        }

        $this->announceIfSustained($grant, $previousSeenAt);

        return $grant;
    }

    /**
     * Says «this viewing counts as watched» — once per grant, on the renewal that
     * crosses the line.
     *
     * ⚠️ THE CLOCK IS OURS. The age is `now − created_at` of a row this server
     * wrote, so reaching it takes real time with the watermark renewing; the
     * `position_seconds` beside it is whatever the client typed and is never
     * read here. `renewed_count` is not a clock either — nothing enforces a
     * minimum gap between renewals, so a script could run it to any number in a
     * second.
     *
     * The window is (previous renewal, this renewal], so exactly one renewal of
     * a grant can satisfy it however irregular the loop is — a viewer with the
     * page open for two hours announces once, not once a minute. A second grant
     * (the page reopened) may announce again; the listener's own write is
     * conditional, which is what makes the fact once per PERSON.
     */
    private function announceIfSustained(PlaybackGrant $grant, ?CarbonInterface $previousSeenAt): void
    {
        if ($previousSeenAt === null) {
            return;
        }

        $threshold = $this->watchedAfterSeconds($grant->asset->duration_seconds);
        $issuedAt = $grant->created_at;

        if ($issuedAt === null) {
            return;
        }

        $before = $issuedAt->diffInSeconds($previousSeenAt, true);
        $now = $issuedAt->diffInSeconds(now(), true);

        if ($before < $threshold && $now >= $threshold) {
            PlaybackSustained::dispatch((int) $grant->media_asset_id, (int) $grant->user_id);
        }
    }

    private function watchedAfterSeconds(?int $durationSeconds): int
    {
        if ($durationSeconds !== null && $durationSeconds > 0) {
            $share = (float) PlatformSettings::get('media.watched_share', 0.5);

            return max(1, (int) ceil($durationSeconds * $share));
        }

        return max(1, (int) PlatformSettings::get('media.watched_fallback_seconds', 600));
    }

    /**
     * Best-effort: a viewer with no enrolment row (a teacher previewing their own
     * lesson, a free lesson) simply has no position to remember, and that is not
     * an error worth failing the renewal over.
     */
    private function rememberPosition(PlaybackGrant $grant, int $positionSeconds): void
    {
        $lessonId = (int) $grant->asset->owner_id;

        $enrollment = Enrollment::withoutWorkspaceScope()
            ->where('student_user_id', $grant->user_id)
            ->where('workspace_id', $grant->workspace_id)
            ->whereHas('course.lessons', fn (Builder $query) => $query
                ->withoutGlobalScope(WorkspaceScope::class)
                ->where('lessons.id', $lessonId))
            ->first();

        if ($enrollment === null) {
            return;
        }

        // Created, not merely updated: the first minute of the first viewing is
        // exactly when there is no progress row yet, so an update-only write
        // remembered nothing for every viewer who had not already finished
        // something in the lesson.
        LessonProgress::withoutWorkspaceScope()->updateOrCreate(
            [
                'enrollment_id' => $enrollment->getKey(),
                'lesson_id' => $lessonId,
            ],
            [
                'workspace_id' => $enrollment->workspace_id,
                'last_position_seconds' => max(0, $positionSeconds),
            ],
        );
    }
}
