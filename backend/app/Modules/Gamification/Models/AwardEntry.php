<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Gamification\AwardEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only. The sum of the entries IS the balance (FR-004 · FR-005).
 *
 * ⚠️ NO BelongsToWorkspace, EVEN THOUGH IT CARRIES workspace_id. The column is
 * CONTEXT — which teacher the point was earned with — and the row belongs to a
 * platform user who is a member of no workspace at all. Adding the trait would
 * auto-fill it from a context that resolves to null for every student and every
 * queued job, and would hide a student's own history from them behind a scope.
 * The Action passes the value explicitly.
 *
 * The refusal to update or delete is enforced here as well as in the Action, the
 * same reasoning as Settlement's LedgerEntry: the Action is only the path the API
 * uses, and a ledger with one honest route and three quiet ones is not a ledger.
 *
 * @property int $xp
 * @property int $coins
 * @property int $level_band
 * @property int $reversal_of_id
 */
class AwardEntry extends BaseModel
{
    /** @use HasFactory<AwardEntryFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'student_user_id',
        'action_key',
        'xp',
        'coins',
        'workspace_id',
        'course_id',
        'lesson_id',
        'level_band',
        'source_type',
        'source_id',
        'reversal_of_id',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'xp' => 'integer',
            'coins' => 'integer',
            'level_band' => 'integer',
            'source_id' => 'integer',
            'reversal_of_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('قيد المنح لا يُعدَّل. التصحيح بقيد عكسي جديد.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('قيد المنح لا يُحذف. التصحيح بقيد عكسي جديد.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
