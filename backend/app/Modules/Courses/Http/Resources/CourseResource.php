<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Resources;

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Http\Resources\CohortResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Course */
class CourseResource extends JsonResource
{
    /**
     * ⚠️ THE GROUPS ARE STAMPED IN FROM OUTSIDE, NEVER FETCHED HERE — the rule
     * Learning's own `CohortResource` already carries (named in prose, because
     * `Modules/Courses` may not import `Modules/Learning`). A Resource runs once
     * per row, so asking `CohortDirectory` in
     * `toArray()` is one query per course plus one schedule read per course; the
     * caller asks once for the whole page and passes the answer in. An unstamped
     * resource sends an empty list rather than reaching for one.
     *
     * ⚠️ A SETTER RATHER THAN A SECOND CONSTRUCTOR ARGUMENT, and that is not
     * taste. `Resource::collection()` maps with `mapInto()`, which passes the
     * COLLECTION KEY as the second argument — so a two-argument constructor
     * turns every `CourseResource::collection(...)` in the tree into a
     * `TypeError: must be of type array, int given`, at runtime, on endpoints
     * that have nothing to do with groups.
     *
     * @var list<array<string, mixed>>
     */
    private array $cohorts = [];

    /** @param  list<array<string, mixed>>  $cohorts */
    public function withCohorts(array $cohorts): static
    {
        $this->cohorts = $cohorts;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            /*
            | The subject the course teaches — required on every write since the
            | day it was found NULL on 77 of 77 rows. Sent as a pair so the edit
            | form can preselect it without a second lookup, and `whenLoaded` so a
            | list that does not eager-load it pays nothing.
            */
            'subject' => $this->whenLoaded('subject', fn (): ?array => $this->subject === null ? null : [
                'uuid' => (string) $this->subject->uuid,
                'label' => (string) $this->subject->name,
            ]),
            /*
            | ⚠️ THE SPELLING OF `PublicCourseCardResource:31`, CHARACTER FOR
            | CHARACTER, AND THE COVER WAS MISSING ONLY FROM THE AUTHENTICATED
            | SIDE. A visitor browsing the marketplace saw the course's cover on
            | its card; the student who bought it saw a page with no image on it
            | at all, because this resource never carried the field.
            |
            | Two spellings of one URL diverge at the first change to the storage
            | disk — and the half nobody opened is the half that breaks.
            */
            'cover_url' => $this->cover_path === null ? null : asset('storage/'.$this->cover_path),
            /*
            | ⚠️ THE STAGE, AND IT HAD NO WRITER AT ALL UNTIL NOW — `subject_id`'s
            | history, one column along. It has been fillable since 006 and named
            | by {@see \Database\Seeders\TaxonomySeeder} as half of the
            | `(subject, grade_level)` settlement-rate key, and no request, form,
            | Action or seeder ever assigned it: NULL on 95 of 96 rows, measured
            | 2026-09-09. So the stage filter this field exists for could only
            | ever have offered one option.
            |
            | The bare slug, matching `grade_levels.slug` — the same undefended
            | text the rate lookup and the `grade:{slug}` leaderboard key carry.
            | The Arabic label is the catalogue's and is read from
            | `/signup/grade-levels`, never restated here.
            */
            'grade_level' => $this->grade_level,
            'slug' => $this->slug,
            'description' => $this->description,
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'status' => $this->status,
            'is_published' => $this->isPublished(),
            'visibility' => $this->visibility,
            'is_sequential' => $this->is_sequential,
            'private_session_minutes' => $this->private_session_minutes,
            /*
            | The promo video as its OWNER sees it (018 · US2).
            |
            | The status travels here and never on the public payload: the
            | teacher needs to know why their button is not showing, and a
            | visitor being told something is hidden pending approval is being
            | told it exists. Two resources, two audiences — and this one is not
            | walked by `PublicFieldAllowlist`.
            */
            'promo_video_id' => $this->promo_video_id,
            'promo_video_status' => $this->promo_video_status,
            'is_free' => $this->isFree(),
            'language' => $this->language,
            'duration_seconds' => $this->duration_seconds,
            'created_at' => $this->created_at,
            'sections' => CourseSectionResource::collection($this->whenLoaded('sections')),
            // Empty when nothing stamped one in — see the constructor.
            'cohorts' => $this->cohorts,
        ];
    }
}
