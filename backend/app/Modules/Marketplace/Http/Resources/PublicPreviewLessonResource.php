<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Resources;

use App\Modules\Courses\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The open embedded lesson, as a visitor with no account reads it (032 · FR-010).
 *
 * Hand-written key by key and measured against
 * `PublicFieldAllowlist::PREVIEW_LESSON` BY EQUALITY — never `parent::toArray()`,
 * which publishes every column the table gains next to every anonymous visitor
 * with nobody deciding.
 *
 * ⚠️ WHAT IS NEEDED TO WATCH IT AND NOTHING ELSE. No publication status, no
 * price, no progress denominator, nothing about the rest of the tree. The
 * course's three fields are here because the enrolment invitation beside the
 * video (FR-013) is built from them, and `slug` is what makes its link.
 *
 * @mixin Lesson
 */
class PublicPreviewLessonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payload = [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'kind' => (string) $this->type,
            /*
            | ⚠️ THE COLUMN, SENT AS IT IS STORED — and what is stored is the
            | url WE BUILT from a closed host set at save time, never what the
            | teacher pasted. That is what makes FR-003 unrepresentable rather
            | than guarded: there is no raw address in this row for a later
            | reader to reach for.
            */
            'embed_url' => $this->external_url,
        ];

        // Spec 032 · FR-018 — omitted entirely when zero, never sent as zero or
        // null. The default is 0 and the teacher writes it by hand, so a zero
        // means «not written»; «٠ دقيقة» is a lie rather than a blank.
        if ((int) $this->duration_seconds > 0) {
            $payload['duration_seconds'] = (int) $this->duration_seconds;
        }

        $payload['course'] = [
            'uuid' => $this->course?->uuid,
            'title' => $this->course?->title,
            'slug' => $this->course?->slug,
        ];

        return $payload;
    }
}
