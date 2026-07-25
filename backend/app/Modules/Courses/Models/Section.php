<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use Database\Factories\Modules\Courses\SectionFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends BaseModel
{
    use BelongsToWorkspace, HasFactory;

    protected $table = 'course_sections';

    protected $fillable = [
        'workspace_id',
        'course_id',
        'title',
        'order',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('order');
    }

    protected static function newFactory(): Factory
    {
        return SectionFactory::new();
    }
}
