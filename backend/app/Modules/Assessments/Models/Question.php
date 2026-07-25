<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use Database\Factories\Modules\Assessments\QuestionFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $type
 * @property string $difficulty
 */
class Question extends BaseModel
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'workspace_id',
        'exam_id',
        'type',
        'difficulty',
        'content',
        'points',
        'explanation',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('order');
    }

    protected static function newFactory(): Factory
    {
        return QuestionFactory::new();
    }
}
