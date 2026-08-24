<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Data\AnnouncementData;
use App\Modules\Community\Models\Announcement;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Write a notice, unpublished (FR-042).
 *
 * ⚠️ THE SCOPE IS FIXED HERE AND NEVER EDITED AFTERWARDS. Once published, the
 * audience is a set of people who have already been told — moving the scope
 * would leave the first group holding a message meant for somebody else and
 * would make the two counters `FR-046` promises describe an audience that no
 * longer exists. Wrong scope, new announcement.
 *
 * ⚠️ AND THE UUID IS RESOLVED IN THIS WORKSPACE, NOT CAST. `exists:courses,uuid`
 * answers a different question: a bare uuid from another teacher's workspace
 * would pass it and then address their students. The `SaveGradingScheme`
 * precedent, and NFR-001أ's rule about identity probes.
 */
class CreateAnnouncement extends Action
{
    public function handle(User $author, AnnouncementData $data): Announcement
    {
        $workspaceId = (int) app(WorkspaceContext::class)->id();

        $scopeId = match ($data->scope) {
            Announcement::SCOPE_ALL => null,
            Announcement::SCOPE_COURSE => $this->resolve(
                Course::query()->where('workspace_id', $workspaceId)->where('uuid', $data->scopeUuid ?? ''),
                'لا يوجد كورس بهذا المعرّف.',
            ),
            Announcement::SCOPE_SESSION => $this->resolve(
                ClassSession::query()
                    ->withoutWorkspaceScope()
                    ->where('workspace_id', $workspaceId)
                    ->where('uuid', $data->scopeUuid ?? ''),
                'لا توجد حصة بهذا المعرّف.',
            ),
            default => throw ValidationException::withMessages([
                'scope' => 'نطاق غير معروف.',
            ]),
        };

        // Not `$data->scopeUuid === null` — an `all` announcement carrying a
        // stray uuid is a teacher who chose a course and then switched the
        // selector back, and silently keeping the id would publish to everyone
        // while the row claims a course.
        if ($data->scope !== Announcement::SCOPE_ALL && $scopeId === null) {
            throw ValidationException::withMessages([
                'scope_uuid' => 'اختر الكورس أو الحصة التي يخصّها الإعلان.',
            ]);
        }

        $announcement = new Announcement([
            'workspace_id' => $workspaceId,
            'author_user_id' => $author->getKey(),
            'scope' => $data->scope,
            'body' => $data->body,
            'is_urgent' => $data->isUrgent,
        ]);

        // `scope_id` is not fillable: it is an internal id and mass-assigning it
        // would be the second way to address another workspace's course.
        $announcement->scope_id = $scopeId;
        $announcement->save();

        return $announcement;
    }

    /** @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query */
    private function resolve(Builder $query, string $message): int
    {
        $model = $query->first();

        if ($model === null) {
            throw ValidationException::withMessages(['scope_uuid' => $message]);
        }

        return (int) $model->getKey();
    }
}
