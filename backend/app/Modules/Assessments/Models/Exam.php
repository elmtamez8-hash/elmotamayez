<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Modules\Courses\Models\Course;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use App\Shared\Traits\IsPublishable;
use Database\Factories\Modules\Assessments\ExamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $status
 */
class Exam extends BaseModel
{
    /** @use HasFactory<ExamFactory> */
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

    /** @return array<string, mixed> */
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

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<ExamItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ExamItem::class)->orderBy('order')->orderBy('id');
    }

    /**
     * Every sitting of this paper, by anybody.
     *
     * ⚠️ NOT NARROWED HERE. A relation that filtered to the current user would be
     * a second answer to «whose attempt is this», in a place no caller can see —
     * and grading and the item analysis both need the whole set. The caller
     * constrains it; `ExamController@index` does so with the reader's own id and
     * `is_practice = false`, because a practice run is not a result.
     *
     * @return HasMany<Attempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    /**
     * The bank questions this exam includes, in their exam order.
     *
     * ⚠️ THIS USED TO BE `hasMany(Question::class)` ON `questions.exam_id`, and
     * the difference is the whole of spec 008: a question is no longer owned by
     * an exam, it is INCLUDED by one. The old relation could not express the same
     * question appearing in three exams, which is the feature.
     *
     * @return BelongsToMany<Question, $this>
     */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'exam_items')
            ->withPivot(['order', 'points_override'])
            ->orderBy('exam_items.order')
            ->orderBy('exam_items.id');
    }
}
