<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Resources;

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * The course's own public page.
 *
 * Hand-written key by key and walked by `PublicExposureTest` against
 * `PublicFieldAllowlist::COURSE_DETAIL` and the nested shapes. Never
 * `parent::toArray()`: that publishes every column the table gains next, to
 * every anonymous visitor, with no decision from anyone.
 *
 * ⚠️ THE PRICE IS HERE ON PURPOSE, AND ONLY HERE. 006 · FR-021هـ took it off
 * browsing cards and left it on «the buyable unit's own page» — this is that
 * page, and until 023 it did not exist.
 *
 * ⚠️ NO ITEM CARRIES A MEDIA PATH, AND ONLY ONE KIND CARRIES A uuid. The title,
 * the kind and the duration are the promise being made; an identifier is an
 * invitation to try the playback endpoint with it — which is still true of every
 * item that HAS something there to open.
 *
 * ⛔ SPEC 032 AMENDS 023 · FR-005/SC-004 DELIBERATELY AND NARROWLY: an `embed`
 * item has no media asset at all, so its uuid opens nothing at that endpoint,
 * and without it a visitor has no way to point at the free preview lesson —
 * which is the whole of US2. See `PublicFieldAllowlist::CURRICULUM_ITEM`.
 *
 * @mixin Course
 */
class PublicCourseDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            // Published for display — the address is the uuid (see
            // ReadPublicCourse: a slug is unique inside one workspace only).
            'slug' => $this->slug,
            'title' => $this->title,
            // Markdown, rendered at display time. A stored `content_html` would
            // be a second copy of the same words drifting from its source at the
            // first typo fix, with a teacher's keyboard attached to an XSS sink.
            'description' => $this->description,
            'cover_url' => $this->cover_path === null ? null : asset('storage/'.$this->cover_path),
            /*
            | The promotional video's ID on the teacher's own channel (018).
            |
            | ⚠️ THE ID, NEVER A URL OR A READY-MADE EMBED ADDRESS. The embed
            | address is a display detail built on each side from the id; put it
            | in the payload and the host becomes part of a stored, shared public
            | contract that cannot be changed without a version.
            |
            | `null` covers BOTH «no video» and «awaiting review», and does not
            | distinguish them on purpose: telling a visitor that something is
            | hidden pending approval is telling them it exists.
            */
            'promo_video_id' => $this->hasApprovedPromoVideo() ? $this->promo_video_id : null,
            'subject' => $this->subjectShape(),
            'grade_level' => $this->grade_level,
            'teacher' => $this->teacherShape(),
            'type' => $this->course_type,
            'lessons_count' => (int) ($this->getAttribute('lessons_count') ?? 0),
            'duration_seconds' => $this->duration_seconds,
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            // Courses carry no reviews of their own yet. Borrowing the teacher's
            // score would rate the wrong thing, and a zero would read as a bad
            // course rather than an unrated one.
            'average_rating' => null,
            'enrolled_count' => (int) ($this->getAttribute('enrolled_count') ?? 0),
            'private_session_minutes' => $this->private_session_minutes,
            'curriculum' => $this->curriculumShape(),
        ];
    }

    /** @return array{slug: string, name: string, icon: string|null}|null */
    private function subjectShape(): ?array
    {
        $subject = $this->subject;

        if ($subject === null) {
            return null;
        }

        return [
            'slug' => (string) $subject->slug,
            'name' => (string) $subject->name,
            'icon' => $subject->icon,
        ];
    }

    /**
     * The author (FR-004) — and the same two conditions the card's byline asks.
     *
     * A Resource must not assume its caller applied a scope: `WorkspaceScope`
     * adds no condition for a guest, so the day this is rendered from a query
     * that forgot `publiclyListed()`, this is what keeps the page from linking
     * to a profile that refuses to open. Both attributes come from the eager
     * load the Action already paid for, so it costs nothing.
     *
     * @return array{uuid: string, slug: string|null, name: string, photo_url: string|null, trust_score: int|null, trust_score_band: string}|null
     */
    private function teacherShape(): ?array
    {
        $creator = $this->creator;
        $profile = $creator?->teacherProfile;

        if ($creator === null || $profile === null) {
            return null;
        }

        if (! $profile->is_publicly_listed || $profile->approval_status !== TeacherProfile::STATUS_APPROVED) {
            return null;
        }

        return [
            'uuid' => (string) $profile->uuid,
            'slug' => $profile->slug,
            'name' => $creator->name,
            'photo_url' => $profile->photo_path === null ? null : asset('storage/'.$profile->photo_path),
            // ⚠️ A score below the data threshold is `null` with band
            // `building`, never `0` — a zero reads as «rated badly» about a
            // teacher nobody has rated yet.
            'trust_score' => $profile->trust_score,
            'trust_score_band' => $profile->trustScoreBand(),
        ];
    }

    /**
     * Sections → chapters → titles, grouped from the one flat ordered read the
     * Action performed.
     *
     * Grouping here rather than issuing a query per level: a nested eager load
     * would be three reads and this is one, and the ordering is already the
     * teaching order (section, chapter, lesson) that access itself is derived
     * from.
     *
     * @return list<array{title: string, chapters: list<array{title: string, items: list<array<string, mixed>>}>}>
     */
    private function curriculumShape(): array
    {
        /** @var Collection<int, Lesson> $lessons */
        $lessons = $this->getRelationValue('curriculumLessons') ?? collect();

        $sections = [];

        foreach ($lessons->groupBy(fn (Lesson $lesson): int => (int) $lesson->section_id) as $inSection) {
            $chapters = [];

            foreach ($inSection->groupBy(fn (Lesson $lesson): int => (int) $lesson->chapter_id) as $inChapter) {
                $items = [];

                foreach ($inChapter as $lesson) {
                    $item = [
                        'title' => (string) $lesson->title,
                        'kind' => (string) $lesson->type,
                    ];

                    // Spec 032 · FR-018 — omitted entirely when it is zero. The
                    // column defaults to 0 and the teacher writes it by hand, so
                    // a zero means «not written»: «٠ دقيقة» is a lie, not a
                    // blank. The reader already draws nothing for a missing key.
                    if ((int) $lesson->duration_seconds > 0) {
                        $item['duration_seconds'] = (int) $lesson->duration_seconds;
                    }

                    /*
                    | Spec 032 · FR-019.
                    |
                    | ⛔ `isPubliclyReadable()`, NEVER `isOpen()`. The two keys
                    | below are what makes the row clickable, and the public
                    | lesson door measures with the first — so advertising with
                    | the second publishes PERMANENTLY DEAD LINKS: an open
                    | uploaded video would render clickable and answer the 404
                    | that means «no such thing», leaving neither the visitor nor
                    | the teacher a reason.
                    |
                    | ABSENT on every other item, never null: the shape of the
                    | data is what stops a link being built (`CourseCurriculum`'s
                    | own docblock).
                    */
                    if ($lesson->isPubliclyReadable()) {
                        $item['uuid'] = (string) $lesson->uuid;
                        $item['is_open'] = true;
                    }

                    $items[] = $item;
                }

                $chapters[] = [
                    'title' => (string) $inChapter->first()?->chapter?->title,
                    'items' => $items,
                ];
            }

            $sections[] = [
                'title' => (string) $inSection->first()?->section?->title,
                'chapters' => $chapters,
            ];
        }

        return $sections;
    }
}
