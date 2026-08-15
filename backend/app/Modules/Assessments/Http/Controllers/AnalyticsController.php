<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Http\Resources\ConceptStatResource;
use App\Modules\Assessments\Http\Resources\QuestionStatResource;
use App\Modules\Assessments\Jobs\RollUpQuestionStatsJob;
use App\Modules\Assessments\Models\ConceptStat;
use App\Modules\Assessments\Models\QuestionStat;
use App\Modules\Tenancy\Support\Permissions;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Item analysis, read from the rollup and from nowhere else (FR-014).
 *
 * ⚠️ NOT ONE AGGREGATE RUNS HERE. Every number on this screen was computed by
 * {@see RollUpQuestionStatsJob}; a GROUP BY added
 * to this controller "just for the freshest figure" is three scans over the two
 * fastest-growing tables on every page open, which is the cost the rollup was
 * built to remove.
 *
 * ⚠️ AND THE PLATFORM-WIDE READ DECLARES `withoutWorkspaceScope()` ON EVERY
 * QUERY AND AGAIN INSIDE EVERY EAGER LOAD. `WorkspaceContext::id()` falls back
 * to `users.last_workspace_id` for a super admin too, so a cross-teacher report
 * left scoped shows ONE teacher's numbers and calls them the platform's — and it
 * passes its own test on a single-workspace fixture. The bypass is per model:
 * `->with('question')` runs Question's global scope inside the relation query
 * and returns null for every row outside the reader's fallback workspace.
 */
class AnalyticsController extends Controller
{
    public function questions(Request $request): JsonResponse
    {
        $platform = $this->wantsPlatformScope($request);

        $query = QuestionStat::query()
            ->with(['question' => $this->relation($platform, ['concept' => $this->relation($platform)])]);

        if ($platform) {
            $query->withoutWorkspaceScope();
        }

        $stats = $this->ordered($query)->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            // Wrapped by hand: JsonResource::withoutWrapping() is on globally, and
            // a bare array reaches the client as an undefined `data`.
            'data' => QuestionStatResource::collection($stats->items()),
            'meta' => [
                'total' => $stats->total(),
                'current_page' => $stats->currentPage(),
                'last_page' => $stats->lastPage(),
                'scope' => $platform ? 'platform' : 'workspace',
            ],
        ]);
    }

    public function concepts(Request $request): JsonResponse
    {
        $platform = $this->wantsPlatformScope($request);

        $query = ConceptStat::query()
            ->with([
                'concept' => $this->relation($platform),
                'lesson' => $this->relation($platform),
            ]);

        if ($platform) {
            $query->withoutWorkspaceScope();
        }

        // Each concept's overall row first, then its lessons under it — the
        // order the screen reads in, and the reason `is_overall` is on the wire.
        // Unpaginated on purpose: the row count is concepts x lessons, which a
        // teacher can hold in their head, and the page consumes the whole list.
        $query->orderBy('concept_id')->orderBy('lesson_id');

        $stats = $this->ordered($query)->get();

        return response()->json([
            'data' => ConceptStatResource::collection($stats),
            'meta' => ['scope' => $platform ? 'platform' : 'workspace'],
        ]);
    }

    /**
     * Worst first, and the unknown rates last rather than first.
     *
     * A null sorts before every number on SQLite and after every number on
     * MySQL, so left to the database the top of this list is either the
     * questions that most need attention or the ones nobody has sat — differing
     * by engine. The expression settles it in one place.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function ordered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN wrong_pct IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('wrong_pct')
            ->orderByDesc('attempts_count');
    }

    /**
     * ⚠️ Repeated inside every eager load, not only on the outer query.
     *
     * @param  array<string, mixed>  $nested
     */
    private function relation(bool $platform, array $nested = []): Closure
    {
        return function ($query) use ($platform, $nested): void {
            if ($platform) {
                $query->withoutWorkspaceScope();
            }

            if ($nested !== []) {
                $query->with($nested);
            }
        };
    }

    /**
     * Whether this reader asked for — and may have — the cross-teacher view.
     *
     * `analytics.cross_teacher.view` is a PLATFORM permission held by no tenant
     * role (FR-015), so it is asked of the user directly: there is no model to
     * hang a policy off, on the precedent of the outstanding-credits report.
     */
    private function wantsPlatformScope(Request $request): bool
    {
        $user = $this->currentUser($request);

        if ($request->query('scope') !== 'platform') {
            abort_unless($user->can(Permissions::ANALYTICS_VIEW), 403);

            return false;
        }

        abort_unless($user->can(Permissions::ANALYTICS_CROSS_TEACHER_VIEW), 403);

        return true;
    }
}
