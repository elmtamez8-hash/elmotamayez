<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ٠٣٥ — «الحجزُ يُجمِّدُ الرصيدَ ولا يخصمُه».
 *
 * A bridge row. It carries workspace_id for context and is owned by the
 * student, so every read and write goes through withoutWorkspaceScope() WITH
 * the ownership predicate written out by hand — the scope is not a guard here
 * in either direction: for the self-registered student it is inert, and for a
 * student who does carry a last_workspace_id (workspace_members holds student
 * rows — measured) it bites in the WRONG direction and hides a hold they placed
 * with another teacher.
 *
 * ⚠️ `settled_at`, `outcome` and `hold_seq` ARE NOT FILLABLE. The settlement is
 * one conditional UPDATE — `WHERE id = ? AND settled_at IS NULL` — and that is
 * both the check and the claim; mass-assignable, it becomes a second way to
 * claim the lock from outside the transaction that owns it (the
 * `captured_order_id` rule). `lockForUpdate()` is banned: a no-op on SQLite, so
 * a test written around it passes locally and proves nothing about MySQL.
 *
 * @property int $credits
 * @property int $hold_seq
 * @property CarbonInterface $held_at
 * @property CarbonInterface|null $settled_at
 * @property string|null $outcome
 * @property-read Workspace $workspace workspace_id is NOT NULL
 */
class CreditHold extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    public const OUTCOME_CHARGED = 'charged';

    public const OUTCOME_RELEASED = 'released';

    protected $fillable = [
        'workspace_id',
        'credit_balance_id',
        'class_session_id',
        'student_user_id',
        'credits',
        'held_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'hold_seq' => 'integer',
            'held_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CreditBalance, $this> */
    public function balance(): BelongsTo
    {
        return $this->belongsTo(CreditBalance::class, 'credit_balance_id');
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }
}
