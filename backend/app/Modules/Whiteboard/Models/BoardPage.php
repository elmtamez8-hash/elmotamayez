<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Models;

use App\Models\BaseModel;
use App\Modules\Media\Models\MediaAsset;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Whiteboard\BoardPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One 16:9 page: ONE scene document (FR-028), stored as the text that arrived —
 * `longText`, never decoded on the way out (`GET /boards/{board}` returns it raw).
 *
 * ⚠️ NOT FILLABLE: `version`, `client_tab`, `client_rev`. A save is one conditional
 * UPDATE that checks the version AND the lock together (`SaveBoardScene`).
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int $board_id
 * @property int $position
 * @property string $scene
 * @property int $scene_bytes
 * @property int $version
 * @property string|null $client_tab
 * @property int|null $client_rev
 * @property int|null $background_asset_id
 */
class BoardPage extends BaseModel
{
    /** @use HasFactory<BoardPageFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'board_id',
        'position',
        'scene',
        'scene_bytes',
        'background_asset_id',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'scene_bytes' => 'integer',
            'version' => 'integer',
            'client_rev' => 'integer',
        ];
    }

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function backgroundAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'background_asset_id');
    }
}
