<?php

declare(strict_types=1);

namespace App\Modules\Courses\Listeners;

use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\CourseDuration;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Events\MediaAssetReady;

/**
 * Copies the length of a finished upload onto the item that holds it.
 *
 * The duration under a play button has to be the duration of the file above it
 * (FR-015). A teacher typing it is a number that was right the day it was typed
 * and wrong after the first re-upload — so `ManageLessons::update()` refuses the
 * teacher's value for the kinds that carry one, and this fills it instead.
 *
 * An event, not a call from Media into Courses: Media does not know that lessons
 * exist, which is the same reason 005 publishes its recordings this way.
 */
class SyncLessonDurationFromAsset
{
    public function __invoke(MediaAssetReady $event): void
    {
        $asset = $event->asset;

        if ($asset->owner_type !== Lesson::class || $asset->role !== MediaRole::Primary) {
            return;
        }

        if (! $asset->kind->hasDuration() || $asset->duration_seconds === null) {
            return;
        }

        $lesson = Lesson::query()->find($asset->owner_id);

        if ($lesson === null) {
            return;
        }

        $lesson->forceFill(['duration_seconds' => (int) $asset->duration_seconds])->save();

        if ($lesson->course !== null) {
            CourseDuration::recompute($lesson->course);
        }
    }
}
