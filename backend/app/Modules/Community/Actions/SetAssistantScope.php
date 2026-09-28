<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Modules\Community\Data\AssistantScopeData;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confine an assistant to a set of courses, or take the confinement off.
 *
 * ⚠️ THE WHOLE SET IS SENT AND THE WHOLE SET IS WRITTEN. A per-course add/remove
 * pair would make «which courses is this person on» the answer to a sequence of
 * requests rather than to one, and two owners on one screen would interleave into
 * a set neither of them chose. The reorder in 016 arrived at the same shape from
 * the same direction.
 *
 * ⚠️ AND THE COURSES ARE RESOLVED AGAINST THE ASSIGNMENT'S OWN WORKSPACE. The
 * uuids were already validated by the Form Request, and this second read is not
 * belt-and-braces: the Action is also reachable from a seeder and from the panel,
 * where no Form Request ran at all — and where the current context may be another
 * workspace or none, so the global scope is bypassed and the column is named.
 *
 * ⛔ A UUID THAT DOES NOT RESOLVE IS A REFUSAL, NEVER A SILENT DROP. Dropping it
 * was the widening direction: a set made ONLY of foreign or deleted courses
 * resolved to `[]`, and `[]` is «no confinement» — so a request meaning «confine
 * her to this course» made her unconfined across the whole workspace.
 *
 * ⛔ AND ONLY A LIVE ASSISTANT WHO IS STILL A MEMBER HERE IS SCOPED. A withdrawn
 * row keeps its uuid on the team screen (FR-009) and so stays addressable; writing
 * a scope on it would be a confinement for somebody who holds no membership.
 */
class SetAssistantScope extends Action
{
    public function handle(AssistantAssignment $assignment, AssistantScopeData $data): AssistantAssignment
    {
        $workspaceId = (int) $assignment->workspace_id;

        $isMember = WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $assignment->assistant_user_id)
            ->exists();

        if ($assignment->revoked_at !== null || ! $isMember) {
            throw ValidationException::withMessages([
                'assignment' => 'انتهت مهمّة هذا المساعد، فلا تُعدَّل كورساته. ادعُه إلى الفريق من جديد أولاً.',
            ]);
        }

        $courseIds = $data->courseUuids === []
            ? []
            : Course::query()
                ->withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->whereIn('uuid', $data->courseUuids)
                ->pluck('id')
                ->all();

        if (count($courseIds) !== count($data->courseUuids)) {
            throw ValidationException::withMessages([
                'courses' => 'أحد الكورسات المختارة لم يعد متاحاً في مساحتك. حدّث الصفحة واختر من جديد.',
            ]);
        }

        DB::transaction(function () use ($assignment, $courseIds): void {
            $assignment->scopes()->delete();

            foreach ($courseIds as $courseId) {
                $assignment->scopes()->create(['course_id' => $courseId]);
            }
        });

        return $assignment->load('scopes');
    }
}
