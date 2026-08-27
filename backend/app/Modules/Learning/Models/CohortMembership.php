<?php

declare(strict_types=1);

namespace App\Modules\Learning\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Learning\CohortMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's place in one group. Open, then closed — never reopened.
 *
 * Rejoining is a NEW row, because the history is not rewritten: `left` on the
 * 3rd and `joined` on the 9th are two facts, and one row edited twice is
 * neither of them.
 *
 * @property-read Cohort $cohort
 */
class CohortMembership extends BaseModel
{
    /** @use HasFactory<CohortMembershipFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    /*
    | ⚠️ `closed_slot` IS NOT FILLABLE. It is written inside the statement that
    | owns the closing — `0` while open, the row's own id afterwards — and mass
    | assignable it becomes a second door to a second open membership from
    | outside the Action that owns the transition. Same reasoning as
    | `orders.captured_order_id`.
    */
    protected $fillable = [
        'workspace_id',
        'cohort_id',
        'course_id',
        'student_user_id',
        'joined_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Cohort, $this> */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
