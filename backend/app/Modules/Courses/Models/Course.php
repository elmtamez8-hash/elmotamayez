<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use App\Shared\Traits\IsPublishable;
use Database\Factories\Modules\Courses\CourseFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

/**
 * @property string $status
 * @property string $visibility
 */
class Course extends BaseModel
{
    /** @use HasFactory<CourseFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid, IsPublishable, Searchable;

    protected $fillable = [
        'workspace_id',
        'title',
        'slug',
        'description',
        'price',
        'currency',
        'status',
        'visibility',
        'is_sequential',
        'language',
        'duration_seconds',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_sequential' => 'boolean',
            'duration_seconds' => 'integer',
        ];
    }

    protected static function newFactory(): Factory
    {
        return CourseFactory::new();
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('order');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('order');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isFree(): bool
    {
        return (float) $this->price === 0.0;
    }

    protected function searchableAs(): string
    {
        return 'courses_index';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'price' => (float) $this->price,
        ];
    }
}
