<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The student's credits within ONE course (Q-7).
 *
 * A bridge row: it carries workspace_id for context and points at the
 * platform-owned account. It uses BelongsToWorkspace with exactly one sanctioned
 * bypass — the student reading their own account across teachers, which must go
 * through withoutWorkspaceScope() filtered explicitly by
 * student_credit_account_id. Enrollment sets that precedent already:
 * EloquentEnrollmentDirectory does the same, with a comment saying that scoping
 * it "would return nothing and block every operation silently".
 *
 * The course is the context, not the workspace. Summing across courses is a
 * WRONG answer rather than a compressed one: +10 maths and −6 physics shows +4
 * and unblocked, while the design withholds per course precisely so the paid-up
 * course stays open.
 *
 * remaining = purchased − consumed, always.
 *
 * ⚠️ A refund LOWERS both remaining and purchased, and never touches consumed.
 * An earlier note here said it raised remaining while lowering purchased, which
 * cannot hold: the two moves are in opposite directions and the invariant breaks
 * on the first one. A refund returns credits to the platform and cash to the
 * student (spec 007 turns the event into money), so it is the reverse of a
 * purchase. Raising `consumed` instead would keep the invariant true and show the
 * student sessions they never attended — which is why it is written here rather
 * than left to be inferred from arithmetic that admits two answers.
 *
 * @property int $purchased_credits
 * @property int $consumed_credits
 * @property int $remaining_credits
 * @property int $credit_limit_credits
 * @property int $notified_tier
 * @property-read Workspace $workspace workspace_id is NOT NULL
 * @property-read Course $course course_id is NOT NULL
 */
class CreditBalance extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    protected $fillable = [
        'workspace_id',
        'student_credit_account_id',
        'student_user_id',
        'course_id',
        'purchased_credits',
        'consumed_credits',
        'remaining_credits',
        'credit_limit_credits',
        'negative_since',
        'last_transaction_at',
        'notified_tier',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'purchased_credits' => 'integer',
            'consumed_credits' => 'integer',
            'remaining_credits' => 'integer',
            'credit_limit_credits' => 'integer',
            'notified_tier' => 'integer',
            'negative_since' => 'datetime',
            'last_transaction_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StudentCreditAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(StudentCreditAccount::class, 'student_credit_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<CreditTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /** @return HasMany<CreditLot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(CreditLot::class);
    }
}
