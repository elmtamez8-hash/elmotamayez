<?php

declare(strict_types=1);

namespace App\Modules\Learning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgressHistory extends BaseModel
{
    protected $table = 'lesson_progress_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'lesson_progress_id',
        'event',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function progress(): BelongsTo
    {
        return $this->belongsTo(LessonProgress::class, 'lesson_progress_id');
    }
}
