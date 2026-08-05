<?php

declare(strict_types=1);

namespace App\Modules\Media\Models;

use App\Models\BaseModel;
use App\Modules\Media\Enums\CaptionKind;
use App\Modules\Media\Enums\CaptionSource;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Media\MediaCaptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WebVTT track for one asset.
 *
 * There is no transcript column: the readable, searchable transcript is derived
 * from these cues at display time. Storing it twice produces two copies that
 * drift apart the first time someone edits a subtitle.
 *
 * @property CaptionKind $kind
 * @property CaptionSource $source
 */
class MediaCaption extends BaseModel
{
    /** @use HasFactory<MediaCaptionFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'media_asset_id',
        'language',
        'kind',
        'source',
        'storage_path',
        'is_default',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'kind' => CaptionKind::class,
            'source' => CaptionSource::class,
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
