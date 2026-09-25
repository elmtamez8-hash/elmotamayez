<?php

declare(strict_types=1);

namespace App\Modules\Learning\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Learning\CohortTransferRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «أريد الانتقال إلى الأحد ٦م» — and nothing else moves until it is decided.
 *
 * @property string $status
 * @property-read Cohort $toCohort to_cohort_id is NOT NULL — a request always names where it wants to go
 * @property-read Cohort $fromCohort the column is nullable, but `RequestTransfer` refuses `no_membership`
 *                                  before it writes — so a persisted request always names where it came from
 */
class CohortTransferRequest extends BaseModel
{
    /** @use HasFactory<CohortTransferRequestFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Overtaken by events — the group was archived, or the teacher moved them by hand. */
    public const DROPPED = 'dropped';

    /*
    | ⚠️ `pending_slot` AND `status` ARE NOT FILLABLE. Both are written inside the
    | statement that decides the request — the `closed_slot` reasoning, and the
    | reason a decision cannot be made from a PATCH body.
    */
    protected $fillable = [
        'workspace_id',
        'course_id',
        'student_user_id',
        'to_cohort_id',
        'from_cohort_id',
        'student_reason',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    /*
    | ⛔ UNSCOPED ON THE RELATION, like `Enrollment::course()`. `WorkspaceContext::id()`
    | falls back to `users.last_workspace_id`, stamped on every student a teacher,
    | an invitation or a seeder ever added to a workspace — so for a student who
    | bought from a DIFFERENT teacher the scope ANDs the wrong workspace onto this
    | relation and it answers null about a row that exists.
    |
    | It opens no door: the parent row is already filtered or authorised by
    | whoever read it, and this follows the foreign key that row carries — never
    | a row the reader chose. Nothing in the tree uses it as a `whereHas` guard.
    */
    /** @return BelongsTo<Cohort, $this> */
    public function toCohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class, 'to_cohort_id')->withoutGlobalScope(WorkspaceScope::class);
    }

    /** @return BelongsTo<Cohort, $this> */
    public function fromCohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class, 'from_cohort_id')->withoutGlobalScope(WorkspaceScope::class);
    }

    /**
     * The course this request is about.
     *
     * `course_id` is on the row rather than reached through `toCohort` because
     * the unique index that makes «one pending request per course» true needs it
     * without a join — so the relation costs nothing and saves every reader a hop.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
