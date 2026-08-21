<?php

declare(strict_types=1);

namespace App\Modules\Media\Models;

use App\Models\BaseModel;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Media\MediaAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A video, with the provider's reference to it.
 *
 * Workspace-owned: the teacher produced it. Replaces lessons.media, which was an
 * unvalidated JSON blob pointing at a public disk — one copied path that worked
 * forever, for anyone.
 *
 * `provider` and `provider_asset_id` must never appear in a resource (FR-011).
 *
 * @property MediaAssetStatus $status
 * @property MediaKind $kind
 * @property MediaRole $role
 * @property bool $is_downloadable
 * @property array<int, array<string, mixed>>|null $renditions
 * @property CarbonInterface|null $ready_at
 * @property CarbonInterface|null $archived_at retention took the file; the row is the receipt
 */
class MediaAsset extends BaseModel
{
    /** @use HasFactory<MediaAssetFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'owner_type',
        'owner_id',
        'provider',
        'provider_asset_id',
        'kind',
        'role',
        'is_downloadable',
        'status',
        'original_filename',
        'mime_type',
        'size_bytes',
        'duration_seconds',
        'renditions',
        'failure_reason',
        'ready_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => MediaAssetStatus::class,
            'kind' => MediaKind::class,
            'role' => MediaRole::class,
            'is_downloadable' => 'boolean',
            'renditions' => 'array',
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'ready_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<MediaCaption, $this> */
    public function captions(): HasMany
    {
        return $this->hasMany(MediaCaption::class);
    }

    /** @return HasMany<PlaybackGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(PlaybackGrant::class);
    }

    public function isPlayable(): bool
    {
        return $this->status->isPlayable();
    }
}
