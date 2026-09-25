<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Gamification\Enums\RedemptionStatus;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Gamification\RedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's claim on a reward — a bridge row.
 *
 * ⚠️ THE TRAIT DOES NOT GUARD THE STUDENT'S OWN LIST. A student belongs to no
 * workspace, so the scope adds no condition for them: `GET /redemptions` without
 * an explicit `where user_id` returns every redemption on the platform. See
 * {@see CoinBalance} for the full reasoning.
 *
 * @property RedemptionStatus $status
 * @property int $coins_spent
 * @property CarbonInterface|null $decided_at
 */
class Redemption extends BaseModel
{
    /** @use HasFactory<RedemptionFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'user_id',
        'workspace_id',
        'reward_id',
        'coins_spent',
        'claimed_month_key',
        'status',
        'decided_by',
        'decided_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => RedemptionStatus::class,
            'coins_spent' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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
    /** @return BelongsTo<Reward, $this> */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class)->withoutGlobalScope(WorkspaceScope::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
