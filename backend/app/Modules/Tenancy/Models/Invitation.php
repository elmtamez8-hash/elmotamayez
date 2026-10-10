<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property-read Carbon $expires_at
 * @property-read Carbon|null $accepted_at
 * @property-read Workspace $workspace
 */
class Invitation extends BaseModel
{
    use BelongsToWorkspace;

    /**
     * ⛔ THE TOKEN IS STORED AS ITS SHA-256, NEVER AS ITSELF (security scan
     * 2026-10-10, F21). It is a live credential that grants a staff membership,
     * so a database read — a leaked backup, a replica — redeemed every pending
     * invitation. The plain value exists once, on the object `InviteMember`
     * returns, for the response that hands it to the inviter; every lookup
     * hashes what it is given.
     */
    public ?string $plainToken = null;

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    protected $fillable = [
        'workspace_id',
        'email',
        'role',
        'token',
        'expires_at',
        'accepted_at',
        'accepted_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function accepter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }
}
