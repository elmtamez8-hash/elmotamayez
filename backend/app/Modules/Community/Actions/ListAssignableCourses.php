<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Modules\Courses\Models\Course;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\Collection;

/**
 * The courses an assistant can be confined to: every live course of THIS
 * workspace, drafts included (an assistant is often put on a course before it
 * opens), soft-deleted ones never.
 *
 * ⚠️ THE WORKSPACE IS NAMED, NOT INHERITED FROM THE CONTEXT. The global scope
 * gives the same rows for an owner inside their workspace, and nothing at all —
 * or every workspace's — for a caller with another context or none. The column
 * is the answer in all three.
 *
 * ⚠️ AND THE TEACHER IS EAGER-LOADED WITH `first_name,last_name`, never `name`.
 * `name` is an accessor, and selecting it as a column is the blank-name defect
 * `docs/gotchas/database.md` records.
 */
class ListAssignableCourses extends Action
{
    /** @return Collection<int, Course> */
    public function handle(int $workspaceId): Collection
    {
        return Course::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->with('creator:id,first_name,last_name')
            ->orderBy('title')
            ->get(['id', 'uuid', 'workspace_id', 'title', 'cover_path', 'status', 'created_by']);
    }
}
