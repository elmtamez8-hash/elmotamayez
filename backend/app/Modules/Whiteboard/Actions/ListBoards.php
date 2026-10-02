<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Enums\BoardPendingOperation;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Support\BoardOwnership;
use App\Modules\Whiteboard\Support\MemberRoles;
use App\Shared\Actions\Action;
use App\Shared\Contracts\AssistantScopeDirectory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The boards a person may open, newest first (US1, contracts/api.md).
 *
 * ⚠️ NARROWED IN THE QUERY, never by asking the policy row by row: a list that
 * filters after paging shows short pages and a total that counts boards the
 * reader cannot open (gotchas/tenancy.md — «a Filament LIST never calls the row
 * policy», and neither does a paginator). The predicate mirrors `BoardPolicy::view`:
 *  - the workspace manager: every board;
 *  - a confined assistant: their own, and boards of LIVE courses in their scope;
 *  - other staff: their own, boards of live courses, and boards of deleted courses
 *    they created (the owning teacher keeps those, D1).
 * Boards a queued job is building or deleting are hidden from everyone.
 */
final class ListBoards extends Action
{
    public function __construct(
        private readonly AssistantScopeDirectory $scope,
        private readonly MemberRoles $roles,
    ) {}

    /**
     * @param  array{q?: string|null, course?: string|null, lesson?: string|null, mine?: bool}  $filters
     * @return LengthAwarePaginator<int, Board>
     */
    public function handle(User $reader, int $workspaceId, array $filters): LengthAwarePaginator
    {
        $readerId = (int) $reader->getKey();
        $manager = $this->roles->roleIn($reader, $workspaceId) === Roles::TENANT_OWNER
            && ! $this->scope->isAssistantIn($reader, $workspaceId);
        $scoped = $this->scope->scopedCourseIdsFor($reader, $workspaceId);

        $query = Board::query()
            ->where('workspace_id', $workspaceId)
            ->where(fn (Builder $q) => $q
                ->whereNull('pending_operation')
                ->orWhereNotIn('pending_operation', [BoardPendingOperation::Building->value, BoardPendingOperation::Deleting->value]));

        if (! $manager) {
            $query->where(function (Builder $q) use ($readerId, $scoped): void {
                // A course board is the course teacher's (D1), reached by the course
                // clauses below; the creator owns a course-less one only — as `view` says.
                $q->where(fn (Builder $own) => $own->where('owner_user_id', $readerId)->whereNull('course_id'));

                if ($scoped !== null) {
                    $q->orWhereIn('course_id', Course::query()->whereIn('id', $scoped)->select('id'));

                    return;
                }

                $q->orWhereIn('course_id', Course::query()->select('id'))
                    ->orWhereIn('course_id', Course::query()->onlyTrashed()->where('created_by', $readerId)->select('id'));
            });
        }

        if (($filters['mine'] ?? false) === true) {
            $query->where('owner_user_id', $readerId);
        }

        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%');
        }

        if (($course = $filters['course'] ?? null) !== null) {
            $query->whereIn('course_id', Course::query()->withTrashed()->where('uuid', $course)->select('id'));
        }

        if (($lesson = $filters['lesson'] ?? null) !== null) {
            $query->whereIn('lesson_id', Lesson::query()->where('uuid', $lesson)->select('id'));
        }

        $page = $query
            ->with([
                'owner:id,uuid,first_name,last_name',
                'editor:id,uuid,first_name,last_name',
                'lesson:id,uuid,title',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(20);

        BoardOwnership::primeFor($page->getCollection());

        return $page;
    }
}
