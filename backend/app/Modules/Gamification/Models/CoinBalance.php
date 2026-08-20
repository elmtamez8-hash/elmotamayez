<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Gamification\CoinBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The student's coins with ONE teacher — a bridge row (Q0 · FR-028ج).
 *
 * It uses BelongsToWorkspace, and {@see StudentProgress} deliberately does not:
 * the shop belongs to the teacher and the teacher carries the cost of what is
 * redeemed, so coins earned with one teacher are not spendable with another.
 *
 * ⚠️ AND THE TRAIT GUARDS ALMOST NOTHING ON A STUDENT ROUTE. A student is never a
 * member of any workspace — only AcceptInvitation and CreateWorkspace write that
 * pivot — so WorkspaceContext::id() returns null and WorkspaceScope::apply()
 * returns early adding no condition at all. Reading a student's own balances must
 * therefore go through withoutWorkspaceScope() filtered EXPLICITLY by user_id,
 * exactly as Payments\Models\CreditBalance records for the same reason.
 *
 * There is no total across teachers, and no payload offers one: no sum is
 * correct, and a displayed total promises what the shop refuses on the first
 * attempt.
 *
 * @property int $coins
 */
class CoinBalance extends BaseModel
{
    /** @use HasFactory<CoinBalanceFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'user_id',
        'workspace_id',
        'coins',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['coins' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
