<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Support\HasSiblingOrder;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Courses\Support\OrdersSiblings;
use App\Modules\Courses\Support\ReferenceIntegrity;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Courses\LessonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @property string $type
 * @property ContentStatus $status
 * @property int|null $reference_id
 * @property ExamGate|null $exam_gate
 * @property string|null $external_url
 * @property int|null $class_session_id
 */
class Lesson extends BaseModel implements OrdersSiblings
{
    /** @use HasFactory<LessonFactory> */
    use BelongsToWorkspace, HasFactory, HasSiblingOrder, HasUuid;

    public function siblingScopeColumn(): string
    {
        return 'chapter_id';
    }

    protected $fillable = [
        'workspace_id',
        'course_id',
        'section_id',
        'chapter_id',
        // Set when this lesson was published from a live session's recording.
        // Its presence changes who may watch: entitlement comes from a seat in
        // that session, not from enrolment in the course (FR-030).
        'class_session_id',
        'title',
        'type',
        'status',
        'content',
        'external_url',
        // The exam or session this item places. Interpreted by `type` — there is
        // deliberately no second column saying which, because two columns
        // recording one fact can disagree.
        'reference_id',
        // What the referenced exam asks before the course goes on. Null on every
        // other type — a gate on an article is a fact with no meaning (FR-041).
        'exam_gate',
        'order',
        'duration_seconds',
        'is_preview',
        'is_free',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'exam_gate' => ExamGate::class,
            'order' => 'integer',
            'duration_seconds' => 'integer',
            'is_preview' => 'boolean',
            'is_free' => 'boolean',
        ];
    }

    /**
     * Items a student may see: published, inside a published chapter, inside a
     * published section.
     *
     * The chain matters (FR-028). A published lesson in a draft section is
     * hidden, and the teacher is shown that reason rather than the lesson's own
     * state — "published but blocked by its section" is a different problem to
     * solve than "still a draft".
     *
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    public function scopeVisibleToStudents(Builder $query): Builder
    {
        // An item whose exam or session has been deleted points at nothing, and
        // nobody can sit an exam that is gone (FR-045).
        ReferenceIntegrity::apply($query);

        return $query
            ->where('lessons.status', ContentStatus::Published)
            ->whereHas('chapter', fn (Builder $q) => $q->where('status', ContentStatus::Published))
            ->whereHas('section', fn (Builder $q) => $q->where('status', ContentStatus::Published));
    }

    /**
     * The same three conditions as `visibleToStudents`, asked of THIS row.
     *
     * A scope answers "which rows", and two readers need "does this one" —
     * `Enrollment::accessTo` and `IssuePlaybackGrant::mayWatch`, the two places
     * that decide what one student may open. Both had no status check at all, so a
     * draft item's body and its video were served to anyone enrolled; asking the
     * scope again per row would be a second query for a fact already loaded.
     *
     * Reads the parents through `loadMissing`, so a caller that already eager
     * loaded them — both do, for the ordering — pays nothing.
     */
    public function isVisibleChain(): bool
    {
        $this->loadMissing(['section', 'chapter']);

        return $this->status->isVisibleToStudents()
            && $this->chapter?->status->isVisibleToStudents() === true
            && $this->section?->status->isVisibleToStudents() === true;
    }

    /**
     * The items a course's completion percentage is measured against.
     *
     * Two halves. The status chain is asked here, because publishing changes it;
     * everything else is asked by `progressEligible` below, because publishing
     * cannot — which is what lets the impact preview simulate one and reuse the
     * other rather than growing a second denominator (`FR-049` · `SC-018`).
     *
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    public function scopeCountableForProgress(Builder $query): Builder
    {
        return $query
            ->progressEligible()
            ->where('lessons.status', ContentStatus::Published)
            // The chain, exactly as `visibleToStudents` requires it — and the
            // reason this line exists is that it did NOT. A lesson published
            // inside a draft chapter is the state `blockedBy` renders for the
            // teacher as `blocked_by: "chapter"`: hidden from the student by
            // `visibleToStudents`, ungrantable by `accessTo`, and yet counted in
            // the denominator. Nobody could ever complete it, so every enrolled
            // student's ceiling sat below 100% and no certificate issued. A
            // fourth road into the same forever-bug, through the publish chain.
            ->whereHas('chapter', fn (Builder $q) => $q->where('status', ContentStatus::Published))
            ->whereHas('section', fn (Builder $q) => $q->where('status', ContentStatus::Published));
    }

    /**
     * The half of `countableForProgress` that a publish cannot change.
     *
     * Split out for the impact preview (`FR-049`), which has to answer what the
     * denominator WOULD be — so it must simulate the three status conditions and
     * must not simulate anything else. Every condition here is a fact about the
     * item itself that publishing leaves untouched:
     *
     * - a type nothing is asked of (`note`, `link`, an unheld `live_session`)
     *   can never be completed, so it never counts;
     * - a session recording is entitled by a SEAT, not by enrolment (005
     *   `FR-030`), so counting it caps every seatless student below 100% forever;
     * - an item whose exam was deleted points at nothing (`FR-045`), same road.
     *
     * Keeping them in a scope rather than restating them in the preview is the
     * whole point: a preview that drew its own version of "countable" would be a
     * second denominator, and `SC-018` is the promise that there is only one.
     *
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    public function scopeProgressEligible(Builder $query): Builder
    {
        ReferenceIntegrity::apply($query);

        return $query
            ->whereIn('lessons.type', LessonTypeRegistry::completableValues())
            ->whereNull('lessons.class_session_id');
    }

    /**
     * The lesson's own file — its video, its audio, its document.
     *
     * Replaces the old `media` JSON column, which held a path on the public disk
     * — a permanent link that worked forever for anyone who copied it.
     *
     * **Scoped to `primary`, and that is not cosmetic.** Since 016 a lesson may
     * carry attachments beside its own file, and an unscoped `morphOne` returns
     * whichever row the database hands back first. Everything that reads this —
     * the resource's `asset` field, the replace-on-re-upload path, the duration
     * sync — would then be describing an arbitrary worksheet. It fails silently,
     * which is why the scope is here rather than at each call site.
     *
     * @return MorphOne<MediaAsset, $this>
     */
    public function mediaAsset(): MorphOne
    {
        return $this->morphOne(MediaAsset::class, 'owner')->where('role', MediaRole::Primary);
    }

    /**
     * Files attached beside the item, whatever its type (FR-019).
     *
     * A worksheet under a video, the slides under an article. Many per lesson,
     * where the primary is one — enforced in the Action, since MySQL has no
     * partial unique index to say "one row per owner where role = primary".
     *
     * @return MorphMany<MediaAsset, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(MediaAsset::class, 'owner')->where('role', MediaRole::Attachment);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<Chapter, $this> */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
