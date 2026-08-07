<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Resources;

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\TeacherProfile;
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

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'cover_url' => $this->cover_path === null ? null : asset('storage/'.$this->cover_path),
            'teacher' => $this->teacherByline(),
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

    /**
     * The author — but only when the public can actually reach them.
     *
     * The byline is a LINK, and `/teachers/{uuid}` applies `publiclyListed()`. A
     * profile that merely EXISTS is not enough: a teacher still pending review,
     * or rejected, or suspended, has no public page, so a byline pointing at them
     * is a 404 the visitor finds by clicking. That is exactly how "Introduction
     * to Laravel" behaved — its author never finished their application.
     *
     * A course with no author at all, or one whose author never applied to teach,
     * lands in the same place for the same reason: the card renders the title as
     * plain text rather than as a dead link.
     *
     * Both conditions read attributes the `creator.teacherProfile` eager load in
     * `ListPublicCourses` already fetched, so this stays free. Calling
     * `publiclyListed()` here would be one query per card, and a Resource runs
     * once per row.
     *
     * @return array{uuid: string, name: string, photo_url: string|null}|null
     */
    private function teacherByline(): ?array
    {
        $creator = $this->creator;
        $profile = $creator?->teacherProfile;

        if ($creator === null || $profile === null) {
            return null;
        }

        // The same two conditions as TeacherProfile::publicListingConstraints().
        // Workspace participation is not repeated: the course only reached this
        // Resource through publiclyListed(), which already required it, and the
        // profile lives in the workspace that owns the course.
        if (! $profile->is_publicly_listed || $profile->approval_status !== TeacherProfile::STATUS_APPROVED) {
            return null;
        }

        return [
            // The uuid, never a raw created_by: a public payload carries no
            // internal id, and the uuid is what links the card to the profile.
            'uuid' => (string) $profile->uuid,
            'name' => $creator->name,
            'photo_url' => $profile->photo_path === null ? null : asset('storage/'.$profile->photo_path),
        ];
    }

    private function hasDiscount(): bool
    {
        return $this->price_before_discount !== null
            && (float) $this->price_before_discount > (float) $this->price;
    }
}
