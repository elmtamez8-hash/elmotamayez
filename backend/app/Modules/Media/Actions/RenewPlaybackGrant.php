<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Media\Models\PlaybackGrant;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Actions\Action;
use App\Shared\Scopes\WorkspaceScope;
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

        $grant->forceFill([
            'expires_at' => now()->addSeconds($ttl),
            'renewed_count' => $grant->renewed_count + 1,
            'last_seen_at' => now(),
        ])->save();

        if ($positionSeconds !== null) {
            $this->rememberPosition($grant, $positionSeconds);
        }

        return $grant;
    }

    /**
     * Best-effort: a viewer with no enrolment row (a teacher previewing their own
     * lesson, a free lesson) simply has no position to remember, and that is not
     * an error worth failing the renewal over.
     */
    private function rememberPosition(PlaybackGrant $grant, int $positionSeconds): void
    {
        $lessonId = $grant->asset->owner_id;

        LessonProgress::withoutWorkspaceScope()
            ->whereHas('enrollment', fn (Builder $query) => $query
                ->withoutGlobalScope(WorkspaceScope::class)
                ->where('student_user_id', $grant->user_id))
            ->where('lesson_id', $lessonId)
            ->update(['last_position_seconds' => max(0, $positionSeconds)]);
    }
}
