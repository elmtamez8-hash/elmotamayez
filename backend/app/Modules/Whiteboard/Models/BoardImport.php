<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Whiteboard\Enums\BoardImportFailure;
use App\Modules\Whiteboard\Enums\BoardImportStatus;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Whiteboard\BoardImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document being turned into pages (story 4). `status` moves only by a
 * conditional UPDATE on its current value, so it is not fillable.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int $board_id
 * @property int $user_id
 * @property int|null $source_asset_id
 * @property BoardImportStatus $status
 * @property BoardImportFailure|null $failure_reason
 * @property int $dispatch_attempts
 * @property int|null $pages_count
 * @property int|null $insert_after_page_id
 */
class BoardImport extends BaseModel
{
    /** @use HasFactory<BoardImportFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'board_id',
        'user_id',
        'source_asset_id',
        'insert_after_page_id',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => BoardImportStatus::class,
            'failure_reason' => BoardImportFailure::class,
            'dispatch_attempts' => 'integer',
            'pages_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function sourceAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'source_asset_id');
    }
}
