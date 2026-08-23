<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Community\AssistantAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A member of the teacher's team — WORKSPACE-owned (layer 2).
 *
 * The row exists to answer two questions no role can: is this person an
 * assistant HERE, and on which courses. What they may DO is spatie permissions
 * granted from the roles screen (`NFR-004`, ق-١) — there is no abilities column
 * and adding one would be a second permission system beside the first.
 *
 * @property CarbonImmutable|null $revoked_at
 * @property int $workspace_id
 * @property int $assistant_user_id
 * @property int|null $invited_by_user_id
 */
class AssistantAssignment extends BaseModel
{
    /** @use HasFactory<AssistantAssignmentFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'assistant_user_id',
        'invited_by_user_id',
    ];

    /*
    | ⚠️ `revoked_at` IS DELIBERATELY NOT FILLABLE, and `BaseModel` guards nothing
    | by default (`$guarded = []`) — so this list is the only thing that keeps it
    | out. It is claimed by a conditional `WHERE revoked_at IS NULL` update, and
    | mass-assignable it becomes a second way to revoke from outside the statement
    | that owns the claim. The same reason Payments keeps `captured_order_id` out
    | of its fillable list, and Gamification `month_redeemed`.
    */

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * Live assignments only.
     *
     * ⚠️ THIS IS WHAT MAKES REVOCATION INSTANT, and it is why there is no
     * `RevokeAssistantSessions` job. The assignment is read on every request that
     * asks the directory anything, so clearing it takes effect on the NEXT
     * request with no session to end — `SC-003`. Deleting the Sanctum token
     * instead would sign the person out of their OTHER teacher's workspace and
     * answer 401 where the requirement asks for 403.
     *
     * @param  Builder<AssistantAssignment>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /** @return BelongsTo<User, $this> */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The courses this assistant is confined to.
     *
     * ⚠️ AN EMPTY RELATION MEANS EVERY COURSE, never none — see the migration.
     *
     * @return HasMany<AssistantScope, $this>
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(AssistantScope::class);
    }
}
