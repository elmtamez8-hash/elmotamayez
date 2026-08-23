<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Modules\Community\Data\AssistantScopeData;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Courses\Models\Course;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * Confine an assistant to a set of courses, or take the confinement off.
 *
 * ⚠️ THE WHOLE SET IS SENT AND THE WHOLE SET IS WRITTEN. A per-course add/remove
 * pair would make «which courses is this person on» the answer to a sequence of
 * requests rather than to one, and two owners on one screen would interleave into
 * a set neither of them chose. The reorder in 016 arrived at the same shape from
 * the same direction.
 *
 * ⚠️ AND THE COURSES ARE RESOLVED INSIDE THE WORKSPACE SCOPE. The uuids were
 * already validated against it by the Form Request, and this second read is not
 * belt-and-braces: the Action is also reachable from a seeder and from the panel,
 * where no Form Request ran at all.
 */
class SetAssistantScope extends Action
{
    public function handle(AssistantAssignment $assignment, AssistantScopeData $data): AssistantAssignment
    {
        $courseIds = $data->courseUuids === []
            ? []
            : Course::query()
                ->where('workspace_id', $assignment->workspace_id)
                ->whereIn('uuid', $data->courseUuids)
                ->pluck('id')
                ->all();

        DB::transaction(function () use ($assignment, $courseIds): void {
            $assignment->scopes()->delete();

            foreach ($courseIds as $courseId) {
                $assignment->scopes()->create(['course_id' => $courseId]);
            }
        });

        return $assignment->load('scopes');
    }
}
