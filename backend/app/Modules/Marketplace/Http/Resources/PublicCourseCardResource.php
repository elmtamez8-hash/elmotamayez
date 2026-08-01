<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Resources;

use App\Modules\Courses\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public course card.
 *
 * Hand-written key by key and checked against PublicFieldAllowlist::COURSE_CARD
 * by PublicExposureTest. Never parent::toArray() here — that would publish every
 * column the courses table gains next, to every anonymous visitor.
 *
 * @mixin Course
 */
class PublicCourseCardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $enrolled = (int) ($this->getAttribute('enrolled_count') ?? 0);
        $creator = $this->creator;
        $profile = $creator?->teacherProfile;

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'cover_url' => $this->cover_path === null ? null : asset('storage/'.$this->cover_path),
            // The author, not a raw created_by: a public payload never carries an
            // internal id, and the uuid is what links the card to a profile page.
            // A course with no author, or an author who never applied to teach,
            // has no profile to link to — the card renders without the byline
            // rather than with a dead link.
            'teacher' => $profile === null ? null : [
                'uuid' => $profile->uuid,
                'name' => $creator->name,
                'photo_url' => $profile->photo_path === null ? null : asset('storage/'.$profile->photo_path),
            ],
            'type' => $this->course_type,
            'lessons_count' => (int) ($this->getAttribute('lessons_count') ?? 0),
            'duration_seconds' => $this->duration_seconds,
            'price' => (string) $this->price,
            // Null unless there really is a discount. A "before" price equal to the
            // price would render a struck-through number that saves nothing (FR-053).
            'price_before_discount' => $this->hasDiscount() ? (string) $this->price_before_discount : null,
            'currency' => $this->currency,
            // Courses have no reviews yet; the card renders "لا توجد تقييمات بعد"
            // rather than borrowing the teacher's score, which would rate the wrong
            // thing.
            'average_rating' => null,
            'enrolled_count' => $enrolled,
            'is_bestseller' => $enrolled >= (int) config('marketplace.bestseller_enrollments'),
        ];
    }

    private function hasDiscount(): bool
    {
        return $this->price_before_discount !== null
            && (float) $this->price_before_discount > (float) $this->price;
    }
}
