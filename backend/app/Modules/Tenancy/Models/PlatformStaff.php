<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Tenancy\Policies\PlatformStaffPolicy;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's standing to act for the platform.
 *
 * ⚠️ NO `BelongsToWorkspace`, AND THAT IS THE WHOLE POINT. A finance officer
 * approves receipts arriving from every workspace; one scoped to a workspace
 * would have to be a member of each, which is a super admin with extra steps.
 * No global scope touches this table, so the guard is written explicitly in
 * {@see PlatformStaffPolicy} — the same
 * arrangement, and the same hazard, as the other platform-owned tables.
 *
 * @property int $user_id
 * @property string $role
 * @property string $reason
 * @property-read User $user user_id is NOT NULL, with a foreign key behind it
 * @property-read User $assigner assigned_by is NOT NULL — a standing records who granted it
 */
class PlatformStaff extends BaseModel
{
    use HasUuid;

    protected $table = 'platform_staff';

    protected $fillable = [
        'user_id',
        'role',
        'assigned_by',
        'reason',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
