<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use App\Shared\Traits\IsPubliclyListed;
use App\Shared\Traits\IsPublishable;
use Database\Factories\Modules\Courses\CourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

/**
 * @property string $status
 * @property string $visibility
 * @property string $course_type
 * @property string|null $cover_path
 * @property int $price_minor
 * @property int|null $price_before_discount_minor
 * @property int|null $private_session_minutes
 * @property string|null $promo_video_id
 * @property string $promo_video_status
 * @property Carbon|null $promo_video_reviewed_at
 * @property int|null $promo_video_reviewed_by
 * @property Carbon|null $last_delivered_at
 * @property Carbon|null $created_at
 * @property-read User|null $creator created_by is nullable — a course can outlive its author
 */
class Course extends BaseModel
{
    /** @use HasFactory<CourseFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid, IsPubliclyListed, IsPublishable, Searchable;

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_GROUP = 'group';

    public const TYPE_RECORDED = 'recorded';

    /** @return list<string> */
    public static function types(): array
    {
        return [self::TYPE_INDIVIDUAL, self::TYPE_GROUP, self::TYPE_RECORDED];
    }

    protected $fillable = [
        'workspace_id',
        'title',
        'slug',
        'description',
        /*
        | `price` and `currency` are FROZEN, not extended.
        |
        | They price a one-off course order (`orders.kind = course`) and nothing
        | else: CreateOrder reads them, and Course::isFree() compares price to
        | zero to decide free enrolment — so removing them breaks two shipped
        | paths. Spec 006 writes no code that reads them; credit pricing is
        | cost-plus and per session, and a course total is not derivable from a
        | session rate.
        |
        | Known and deliberate: the teacher still sets this value, which FR-021ب
        | forbids for credit pricing. It is a grandfathered exception scoped to
        | course orders, not a precedent. Retiring it is a product decision with
        | revenue consequences and belongs to spec 011.
        */
        'price_minor',
        'currency',
        // The three pricing keys (spec 006, Q-7). teacher_profile_id is the one
        // without which the approved-rate lookup cannot run at all: RateResolver
        // starts from it, and `created_by` is nullable because a course may
        // outlive its author.
        'subject_id',
        'grade_level',
        /*
        | How long a private session in this course lasts (023 · FR-016أ). NULL
        | means the platform default — never «no private sessions».
        |
        | ⚠️ FILLABLE IN THE SAME CHANGE AS ITS MIGRATION. A column mass
        | assignment does not know about is discarded with no exception and no
        | log, and the response echoes what was SENT — so every test written
        | against the body passes over a row holding null.
        */
        'private_session_minutes',
        'teacher_profile_id',
        'status',
        'visibility',
        'is_sequential',
        'language',
        'duration_seconds',
        // Raised by every structural write, and sent back by the client on the
        // next one — an editor whose token is stale is looking at a tree that
        // has changed under them.
        'structure_version',
        'created_by',
        'course_type',
        'cover_path',
        'price_before_discount_minor',
        /*
        | The promotional video's ID on the teacher's own channel (018 · FR-004).
        |
        | ⚠️ THE EXTRACTED ID, NEVER THE PASTED URL. `PromoVideoUrl::extract()`
        | is the one spelling, and storing only the id is what makes FR-008
        | unrepresentable rather than merely checked.
        |
        | ⚠️ AND FILLABLE IN THE SAME CHANGE AS ITS MIGRATION. A column mass
        | assignment does not know about is discarded with no exception and no
        | log, and the response echoes what was SENT — so every test written
        | against the body passes over a row holding null (013's three columns).
        |
        | Its three siblings — status, reviewed_at, reviewed_by — are
        | deliberately NOT here: they are written by ReviewCoursePromoVideo and
        | SetCoursePromoVideo alone. Mass-assignable, the status becomes a second
        | door to the approval decision from outside the action that owns it
        | (the `captured_order_id` rule).
        */
        'promo_video_id',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'price_before_discount_minor' => 'integer',
            'is_sequential' => 'boolean',
            'duration_seconds' => 'integer',
            'private_session_minutes' => 'integer',
            'structure_version' => 'integer',
            // Stamped by Payments' StampCourseDelivery, never by course
            // authoring — and deliberately not fillable: the only writer is that
            // listener's conditional UPDATE (spec 006, FR-021ط).
            'last_delivered_at' => 'datetime',
            'promo_video_reviewed_at' => 'datetime',
        ];
    }

    public const PROMO_NONE = 'none';

    public const PROMO_PENDING = 'pending';

    public const PROMO_APPROVED = 'approved';

    public const PROMO_REJECTED = 'rejected';

    /**
     * Whether the promotional video may be shown to the public (018 · FR-006).
     *
     * ⚠️ THE ONE SPELLING OF THIS QUESTION. The public resource and the
     * teacher's own screen both read it, and two spellings of one question put
     * one answer on the screen and another at the door — the defect this
     * repository has paid for repeatedly.
     *
     * Both conditions, never one: the status alone could outlive a cleared id
     * through some later path, and the id alone is exactly what the review
     * exists to withhold.
     */
    public function hasApprovedPromoVideo(): bool
    {
        return $this->promo_video_status === self::PROMO_APPROVED
            && $this->promo_video_id !== null;
    }

    /** @return HasMany<Section, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('order');
    }

    /** @return HasMany<Chapter, $this> */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('order');
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('order');
    }

    /**
     * The platform-wide subject this course teaches.
     *
     * ⚠️ REFERENCE DATA, NOT A TENANT ROW. `subjects` is deliberately
     * platform-level — one «الرياضيات» for every teacher — so this relation
     * carries no workspace condition and needs none.
     *
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * A course is publicly listed when it is published, explicitly public, and
     * its author has a public page.
     *
     * `visibility` is checked separately from isPublished() on purpose: a course
     * can be published to an academy's own students without being offered to the
     * whole marketplace, and conflating the two would publish the first kind.
     *
     * The author condition is the third, and it is not cosmetic. There is no
     * standalone course page: the card's title links to the AUTHOR's profile,
     * because that is where the course can actually be booked. So a course whose
     * author has no public page is an entry in the marketplace whose only
     * destination is a 404 — the visitor's first interaction with it is the
     * error. Dropping the byline and listing it anyway leaves a card that cannot
     * be clicked at all, which is a different way of publishing nothing.
     *
     * `isPubliclyListed()` below answers the same question row by row for the
     * search index. The two must move together.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function publicListingConstraints(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->whereExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('teacher_profiles')
                    ->whereColumn('teacher_profiles.user_id', 'courses.created_by')
                    ->where('teacher_profiles.is_publicly_listed', true)
                    // The constant rather than 'approved': a literal here is
                    // coupling to another module that nobody can grep for, and
                    // the two would drift the first time the value changed.
                    ->where('teacher_profiles.approval_status', TeacherProfile::STATUS_APPROVED);
            });
    }

    public function isFree(): bool
    {
        return $this->price_minor === 0;
    }

    protected function searchableAs(): string
    {
        return 'courses_index';
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'price_minor' => $this->price_minor,
            // Indexed, not filtered after the fact (R2): Scout runs outside every
            // global scope, so an unpublished course excluded only on the way out
            // would still consume a result slot and leak its title in the count.
            'is_publicly_listed' => $this->isPubliclyListed(),
        ];
    }

    /**
     * Row-level answer to the same question scopePubliclyListed() asks in SQL.
     *
     * Used by the search index, which has no query to attach a scope to. It has
     * to stay in step with `publicListingConstraints()` above — including the
     * author condition, or search would surface exactly the courses the listing
     * refuses to show.
     */
    public function isPubliclyListed(): bool
    {
        $profile = $this->creator?->teacherProfile;

        return $this->isPublished()
            && $this->visibility === 'public'
            && (bool) $this->workspace?->participates_in_marketplace
            && $profile !== null
            && (bool) $profile->is_publicly_listed
            && $profile->approval_status === TeacherProfile::STATUS_APPROVED;
    }
}
