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
            /*
            | ⚠️ THE CARD'S OWN ADDRESS, ADDED WITH `/courses/{slug}` (027).
            | Every public link to a course is built from this card, so a payload
            | without it sends the whole marketplace to the uuid URL — which
            | still resolves, and 308s on arrival, making every listing one extra
            | round trip and one redirect away from the page it names.
            */
            'slug' => $this->slug,
            'title' => $this->title,
            'cover_url' => $this->cover_path === null ? null : asset('storage/'.$this->cover_path),
            'teacher' => $this->teacherByline(),
            'type' => $this->course_type,
            'lessons_count' => (int) ($this->getAttribute('lessons_count') ?? 0),
            'duration_seconds' => $this->duration_seconds,
            /*
            | ⚠️ NO PRICE ON A BROWSE CARD (spec 006, FR-021هـ · T089أ).
            |
            | The price appears when a buyable unit is CHOSEN, and a card in a
            | list is a browsing surface, not the unit. The course's own page is
            | the unit, and it still shows it.
            |
            | Unlike `hourly_rate`, this is NOT added to FORBIDDEN: a one-off
            | course total is not tied to a settlement rate by any equation, so
            | it cannot be used to read what another teacher is paid. It is a
            | placement rule, not a secret.
            */
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
     * On any correctly-built query this now always returns a byline:
     * `Course::publicListingConstraints()` refuses to list a course whose author
     * has no public page, because the card's title links to that page and a
     * listing whose only destination is a 404 is worse than no listing.
     *
     * The check stays here anyway, and not out of caution about the scope: a
     * Resource must not assume its caller applied one. `WorkspaceScope` adds no
     * condition for a guest, so the day someone renders these cards from a query
     * that forgot `publiclyListed()`, this is what keeps the payload from
     * advertising a page that refuses to open.
     *
     * Both conditions read attributes the `creator.teacherProfile` eager load in
     * `ListPublicCourses` already fetched, so this stays free. Calling
     * `publiclyListed()` here would be one query per card, and a Resource runs
     * once per row.
     *
     * @return array{uuid: string, slug: string|null, name: string, photo_url: string|null}|null
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
            'slug' => $profile->slug,
            'name' => $creator->name,
            'photo_url' => $profile->photo_path === null ? null : asset('storage/'.$profile->photo_path),
        ];
    }
}
