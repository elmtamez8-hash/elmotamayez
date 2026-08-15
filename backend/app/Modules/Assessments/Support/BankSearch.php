<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Enums\BloomLevel;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\Question;
use App\Modules\Courses\Models\Lesson;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder as ScoutBuilder;

/**
 * Browsing the bank: five filters and a text box.
 *
 * ⚠️ THE TWO HALVES RUN IN DIFFERENT ENGINES, AND ONLY ONE OF THEM HAS A TENANT
 * SCOPE. The filters are an indexed SQL query behind `WorkspaceScope`. Free text
 * is Scout, which talks to the search engine directly — outside every global
 * scope — so `workspace_id` is added to the query BY HAND, and the filters are
 * added there too rather than applied to the hydrated rows: a filter applied
 * after the engine answers still lets another teacher's question consume a
 * result slot and count toward the total (NFR-007).
 *
 * That is why the filter columns are in `toSearchableArray()`. Anything this
 * class can filter on, the index can filter on.
 */
final class BankSearch
{
    /**
     * @param  array<string, mixed>  $filters  concept · lesson · difficulty · bloom · active · q
     * @param  list<string>  $with  the caller's declared eager-load plan, applied to
     *                              BOTH branches — a Resource runs once per row, so a
     *                              relation missing from here is an N+1 by construction
     * @return LengthAwarePaginator<int, Question>
     */
    public function paginate(array $filters, array $with = [], int $perPage = 20): LengthAwarePaginator
    {
        $term = trim((string) ($filters['q'] ?? ''));

        if ($term !== '') {
            return $this->scout($term, $filters)
                ->query(fn ($query) => $query->with($with)->withCount('examItems'))
                ->paginate($perPage);
        }

        $query = Question::query()->with($with)->withCount('examItems');

        // Column order matches the composite index the migration declared:
        // (workspace_id, concept_id, difficulty) and (workspace_id, lesson_id).
        if (($conceptId = $this->resolve(Concept::class, $filters['concept'] ?? null)) !== null) {
            $query->where('concept_id', $conceptId);
        }

        if (($lessonId = $this->resolve(Lesson::class, $filters['lesson'] ?? null)) !== null) {
            $query->where('lesson_id', $lessonId);
        }

        if (($difficulty = $this->stringFilter($filters['difficulty'] ?? null)) !== null) {
            $query->where('difficulty', $difficulty);
        }

        if (($bloom = $this->bloomFilter($filters['bloom'] ?? null)) !== null) {
            $query->where('bloom_level', $bloom->value);
        }

        // Absent means "only what I can still use". A disabled question stays
        // readable for ever — every past attempt renders through it — but a bank
        // that offers it by default is a bank that grows monotonically.
        $query->where('is_active', $this->activeFilter($filters['active'] ?? null));

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /**
     * The engine-side query, built where a test can read it back.
     *
     * Exposed rather than inlined because the suite runs `SCOUT_DRIVER=null`: an
     * assertion on the RESULTS of this branch passes against an engine that
     * returns nothing whatever the constraints say. The workspace guard is
     * verified on the builder itself.
     *
     * @param  array<string, mixed>  $filters
     * @return ScoutBuilder<Question>
     */
    public function scout(string $term, array $filters = []): ScoutBuilder
    {
        $builder = Question::search($term);

        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId !== null) {
            $builder->where('workspace_id', $workspaceId);
        }

        if (($conceptId = $this->resolve(Concept::class, $filters['concept'] ?? null)) !== null) {
            $builder->where('concept_id', $conceptId);
        }

        if (($lessonId = $this->resolve(Lesson::class, $filters['lesson'] ?? null)) !== null) {
            $builder->where('lesson_id', $lessonId);
        }

        if (($difficulty = $this->stringFilter($filters['difficulty'] ?? null)) !== null) {
            $builder->where('difficulty', $difficulty);
        }

        if (($bloom = $this->bloomFilter($filters['bloom'] ?? null)) !== null) {
            $builder->where('bloom_level', $bloom->value);
        }

        // Indexed as an int: engines filter on scalars, and `false` reaches
        // Meilisearch as an empty string that matches every row.
        $builder->where('is_active', $this->activeFilter($filters['active'] ?? null) ? 1 : 0);

        return $builder;
    }

    /**
     * A uuid from the query string into the row's internal id.
     *
     * Resolved through the model so `WorkspaceScope` answers: a uuid belonging to
     * another teacher resolves to null and filters nothing, rather than filtering
     * by an id from a workspace the reader cannot see.
     *
     * @param  class-string<Model>  $model
     */
    private function resolve(string $model, mixed $uuid): ?int
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $id = $model::query()->where('uuid', $uuid)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function stringFilter(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['easy', 'medium', 'hard'], true) ? $value : null;
    }

    private function bloomFilter(mixed $value): ?BloomLevel
    {
        return is_string($value) ? BloomLevel::tryFrom($value) : null;
    }

    private function activeFilter(mixed $value): bool
    {
        return ! in_array($value, ['0', 'false', false, 0], true);
    }
}
