<?php

declare(strict_types=1);

namespace App\Modules\Learning\Models;

use App\Models\BaseModel;
use App\Modules\Courses\Models\Lesson;
use App\Shared\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $status
 */
class LessonProgress extends BaseModel
{
    use BelongsToWorkspace;

    protected $table = 'lesson_progress';

    protected $fillable = [
        'workspace_id',
        'enrollment_id',
        'lesson_id',
        'status',
        'started_at',
        'completed_at',
        'time_spent_seconds',
        'last_position',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'time_spent_seconds' => 'integer',
            'last_position' => 'array',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(ProgressHistory::class, 'lesson_progress_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
