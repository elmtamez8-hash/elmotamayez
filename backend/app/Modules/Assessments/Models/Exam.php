<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Modules\Courses\Models\Course;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use App\Shared\Traits\IsPublishable;
use Database\Factories\Modules\Assessments\ExamFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $status
 */
class Exam extends BaseModel
{
    use BelongsToWorkspace, HasFactory, HasUuid, IsPublishable;

    protected $fillable = [
        'workspace_id',
        'course_id',
        'title',
        'description',
        'duration_minutes',
        'passing_score',
        'max_attempts',
        'shuffle_questions',
        'shuffle_answers',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'passing_score' => 'integer',
            'max_attempts' => 'integer',
            'shuffle_questions' => 'boolean',
            'shuffle_answers' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('id');
    }

    protected static function newFactory(): Factory
    {
        return ExamFactory::new();
    }
}
