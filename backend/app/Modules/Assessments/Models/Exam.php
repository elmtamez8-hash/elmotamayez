<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Modules\Assessments\Exceptions\ExamDeletionRefused;
use App\Modules\Courses\Models\Course;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use App\Shared\Traits\IsPublishable;
use Database\Factories\Modules\Assessments\ExamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /*
    | ⛔ A PAPER SOMEBODY SAT IS NEVER DELETED — THE SAME RULE, AND THE SAME
    | PLACE, AS `Course::booted()`.
    |
    | Three doors reached `$exam->delete()`: the API's `destroy`, and the panel's
    | row and bulk deletes — and the bulk one asked Filament's `deleteAny` alone,
    | so no per-row check ever ran. `exam_attempts.exam_id` carries no foreign
    | key, so a deleted exam left its attempts and their `exam_answers` pointing
    | at nothing: a student's grade with no paper behind it.
    |
    | ⚠️ AND THIS IS WHY NO DELETE HERE CALLS
    | `QuestionStatRollupState::requestFullRecompute()`. The incremental item
    | analysis cannot see a DELETE (`docs/gotchas/assessments.md`), so any door
    | that removes answers must raise that flag. Refusing every exam that has an
    | attempt means no exam deletion removes an answer at all. Loosening this
    | refusal — deleting an exam WITH attempts — must add that call in the same
    | change.
    */
    protected static function booted(): void
    {
        static::deleting(function (Exam $exam): void {
            $refusal = $exam->deletionRefusal();

            if ($refusal !== null) {
                throw new ExamDeletionRefused($refusal);
            }
        });
    }

    /**
     * Why this exam may not be deleted, or null when it may.
     *
     * ⚠️ A RAW TABLE, NOT `Attempt`. `Attempt` is workspace-scoped, and a
     * platform officer deleting from `/admin` resolves a context from their own
     * `users.last_workspace_id` — the scope would AND that workspace on, count
     * zero attempts on another teacher's paper, and wave the deletion through.
     * Practice attempts count too: they carry answers the rollup reads.
     */
    public function deletionRefusal(): ?string
    {
        if (DB::table('exam_attempts')->where('exam_id', $this->getKey())->exists()) {
            return 'لا يمكن حذف هذا الاختبار لأنّ طلاباً دخلوه، وحذفُه يُفقدُهم درجاتِهم.';
        }

        return null;
    }

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
        return $this->hasMany(ExamItem::class)->withoutGlobalScope(WorkspaceScope::class)->orderBy('order')->orderBy('id');
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
