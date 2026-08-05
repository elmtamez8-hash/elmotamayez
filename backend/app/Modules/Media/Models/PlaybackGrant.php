<?php

declare(strict_types=1);

namespace App\Modules\Media\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Media\PlaybackGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permission to play one asset, for a few minutes, from one session.
 *
 * A row rather than a signed URL. A signature buys expiry without a query, and
 * we query anyway — for the asset, for the session, for revocation. What a
 * signature cannot do is die early, and this must die the moment the session
 * ends, the enrolment lapses, or the watermark stops renewing.
 *
 * A bridge entity: it carries workspace_id for context but has no
 * BelongsToWorkspace, because it is issued and consumed with no workspace
 * current.
 *
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $last_seen_at
 * @property-read MediaAsset $asset media_asset_id is NOT NULL, so it always resolves
 * @property-read User $user user_id is NOT NULL, so it always resolves
 * @property-read AuthSession $session auth_session_id is NOT NULL, so it always resolves
 */
class PlaybackGrant extends BaseModel
{
    /** @use HasFactory<PlaybackGrantFactory> */
    use HasFactory, HasUuid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'media_asset_id',
        'user_id',
        'auth_session_id',
        'expires_at',
        'revoked_at',
        'renewed_count',
        'issued_ip_hash',
        'last_seen_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'renewed_count' => 'integer',
        ];
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AuthSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AuthSession::class, 'auth_session_id');
    }
}
