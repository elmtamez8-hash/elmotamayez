<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use Database\Factories\Modules\Courses\SectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends BaseModel
{
    /** @use HasFactory<SectionFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $table = 'course_sections';

    protected $fillable = [
        'workspace_id',
        'course_id',
        'title',
        'order',
        'is_published',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'order' => 'integer',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<Chapter, $this> */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('order');
    }
}
