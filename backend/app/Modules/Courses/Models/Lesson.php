<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Courses\LessonFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $type
 */
class Lesson extends BaseModel
{
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'course_id',
        'section_id',
        'chapter_id',
        'title',
        'type',
        'content',
        'order',
        'duration_seconds',
        'is_preview',
        'is_free',
        'media',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'duration_seconds' => 'integer',
            'is_preview' => 'boolean',
            'is_free' => 'boolean',
            'media' => 'array',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    protected static function newFactory(): Factory
    {
        return LessonFactory::new();
    }
}
