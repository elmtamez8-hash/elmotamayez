<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Resources;

use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /marketplace/courses/{courseKey}/trial` (spec 040).
 *
 * An embed carries its `embed_url`, as the public lesson door does. An upload
 * carries a playback descriptor whose `manifest_url` is OUR stream route, never
 * the CDN's — no library or video id in any payload (media.md). No grant, no
 * watermark, no renewal, no resume: nothing is recorded against a guest.
 *
 * Every key is in `PublicFieldAllowlist::COURSE_TRIAL` and `COURSE_TRIAL_PLAYBACK`.
 */
final class PublicCourseTrialResource extends JsonResource
{
    public function __construct(private readonly Course $course, Lesson $lesson)
    {
        parent::__construct($lesson);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Lesson $lesson */
        $lesson = $this->resource;
        $isVideo = $lesson->type === LessonType::Video->value;
        $duration = (int) ($isVideo
            ? ($lesson->mediaAsset->duration_seconds ?? $lesson->duration_seconds)
            : $lesson->duration_seconds);

        $payload = [
            'uuid' => (string) $lesson->uuid,
            'title' => (string) $lesson->title,
            'kind' => $isVideo ? 'video' : 'embed',
            'course' => [
                'uuid' => (string) $this->course->uuid,
                'title' => (string) $this->course->title,
                'slug' => (string) $this->course->slug,
            ],
        ];

        // Absent rather than 0: «not written» is not «zero minutes».
        if ($duration > 0) {
            $payload['duration_seconds'] = $duration;
        }

        if ($isVideo) {
            $ttl = max(60, (int) PlatformSettings::get('media.trial_link_ttl_seconds'));
            $key = (string) ($this->course->slug ?? $this->course->uuid);

            $payload['playback'] = [
                'manifest_url' => '/api/v1/marketplace/courses/'.rawurlencode($key).'/trial/stream',
                'format' => 'hls',
                // Two thirds of the link's life, as `PlaybackGrantResource` does,
                // so the player comes back for a fresh link before this one dies.
                'reload_after_seconds' => max(30, intdiv($ttl * 2, 3)),
            ];
        } else {
            $payload['embed_url'] = (string) $lesson->external_url;
        }

        return $payload;
    }
}
