<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Modules\Assessments\Enums\BloomLevel;
use App\Modules\Assessments\Support\BankSearch;
use App\Modules\Courses\Models\Lesson;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Assessments\QuestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

/**
 * A question in the teacher's bank.
 *
 * ⚠️ IT USED TO BELONG TO ONE EXAM, AND `exam_id` IS GONE. Inclusion in an exam
 * is `exam_items` now, which is what lets one question serve three exams as one
 * row. The column was not emptied and kept — a column full in old rows and empty
 * in new ones is two sources of truth about the same question, and the first
 * reader to write `where exam_id` gets half an answer with no error.
 *
 * @property string $type
 * @property string $difficulty
 * @property BloomLevel $bloom_level
 * @property int $points
 * @property-read Concept $concept concept_id is NOT NULL since the 008 chain locked it
 */
class Question extends BaseModel
{
    /** @use HasFactory<QuestionFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid, Searchable;

    protected $fillable = [
        'workspace_id',
        'concept_id',
        'lesson_id',
        'type',
        'difficulty',
        'bloom_level',
        'content',
        'content_hash',
        'points',
        'explanation',
        'is_active',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'is_active' => 'boolean',
            'bloom_level' => BloomLevel::class,
        ];
    }

    /**
     * The hash the import's duplicate policy compares.
     *
     * Derived from the text and nowhere else, so two teachers typing the same
     * question in the same workspace collide and the same teacher's two exams do
     * not — they are different workspaces' rows only when they are.
     */
    public static function hashOf(string $content): string
    {
        return hash('sha256', trim($content));
    }

    /**
     * What the search engine holds.
     *
     * ⚠️ EVERY FILTER COLUMN IS INDEXED, NOT JUST THE TEXT. Scout runs outside
     * every global scope, so a filter applied to the rows it returns arrives too
     * late: another teacher's question has already consumed a result slot and
     * counted toward the total. {@see BankSearch}
     * hands each of these to the engine instead, `workspace_id` first.
     *
     * The explanation is deliberately absent — it is the answer key in prose, and
     * a search index is one misconfigured engine away from being readable.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'concept_id' => $this->concept_id,
            'lesson_id' => $this->lesson_id,
            'difficulty' => $this->difficulty,
            'bloom_level' => $this->bloom_level->value,
            'content' => $this->content,
            // An int rather than a bool: engines filter on scalars, and `false`
            // reaches Meilisearch as an empty string that matches everything.
            'is_active' => $this->is_active ? 1 : 0,
        ];
    }

    /** @return BelongsTo<Concept, $this> */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return HasMany<QuestionOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('order');
    }

    /** @return HasMany<ExamItem, $this> */
    public function examItems(): HasMany
    {
        return $this->hasMany(ExamItem::class);
    }

    /**
     * Questions a teacher may still put into a new exam.
     *
     * Disabled ones stay readable for ever — every past attempt renders through
     * them — they simply stop being offered (FR-005).
     *
     * @param  Builder<Question>  $query
     * @return Builder<Question>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Essays are graded by a person; nothing else in this product may claim to. */
    public function isEssay(): bool
    {
        return $this->type === 'essay';
    }
}
