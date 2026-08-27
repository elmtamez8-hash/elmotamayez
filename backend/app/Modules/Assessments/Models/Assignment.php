<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piece of homework: what is due, when, and what happens if it is late.
 *
 * ⚠️ A DRAFT BLOCKS NOTHING (FR-042). US7 lets an assignment hold the next
 * session shut, and an unpublished one doing that means a teacher who started
 * writing homework on Tuesday and never finished has locked their whole class
 * out of Wednesday — with no message naming a cause they can act on. The scope
 * below is the single reader of that rule.
 *
 * @property string $status
 * @property string $submission_type
 * @property string $late_policy
 * @property int $points
 */
class Assignment extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const TYPE_TEXT = 'text';

    public const TYPE_FILE = 'file';

    /**
     * ⚠️ DEFINED AND NOT ACCEPTED YET, and the refusal is deliberate.
     *
     * FR-044 names three ways to hand work in; two are built. Nothing wires bank
     * questions to an assignment — no item table, no attempt, no marking path —
     * so a teacher who picked this got a plain text box, and a student typed
     * into it something the teacher never asked for. The constant stays because
     * the column will hold it; SaveAssignment refuses the value until the flow
     * behind it exists.
     */
    public const TYPE_QUESTIONS = 'questions';

    public const LATE_ACCEPT = 'accept';

    public const LATE_REJECT = 'reject';

    public const LATE_PENALTY = 'penalty';

    protected $fillable = [
        'workspace_id',
        'course_id',
        'lesson_id',
        'class_session_id',
        'title',
        'description',
        'points',
        'due_at',
        'submission_type',
        'late_policy',
        'late_penalty_pct_per_day',
        'late_penalty_cap_pct',
        'status',
        'published_at',
        'created_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'due_at' => 'datetime',
            'published_at' => 'datetime',
            'late_penalty_pct_per_day' => 'decimal:2',
            'late_penalty_cap_pct' => 'decimal:2',
        ];
    }

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The course this homework belongs to, if it belongs to one.
     *
     * `course_id` has been fillable since 008 and carried by every real row; the
     * relation was simply never written, so «this course's homework» could only
     * be asked by joining on an internal id from outside the model.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function acceptsLate(): bool
    {
        return $this->late_policy !== self::LATE_REJECT;
    }

    /**
     * @param  Builder<Assignment>  $query
     * @return Builder<Assignment>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }
}
