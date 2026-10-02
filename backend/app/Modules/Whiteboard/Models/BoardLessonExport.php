<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Models;

use App\Models\BaseModel;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Models\MediaAsset;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Whiteboard\BoardLessonExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which lesson attachment came from which board (story 5), so exporting again
 * REPLACES it (Q2) instead of stacking a second PDF on the lesson.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int $board_id
 * @property int $lesson_id
 * @property int|null $media_asset_id
 */
class BoardLessonExport extends BaseModel
{
    /** @use HasFactory<BoardLessonExportFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'board_id',
        'lesson_id',
        'media_asset_id',
    ];

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
