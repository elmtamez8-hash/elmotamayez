<?php

declare(strict_types=1);

namespace App\Modules\Media\Policies;

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Contracts\SessionCourseDirectory;

/**
 * Managing an asset is managing lesson content, so it rides on LESSONS_MANAGE
 * rather than a permission of its own. A permission with no distinct actor is
 * authorisation surface bought for nothing.
 *
 * Watching is NOT covered here: that is entitlement, not permission, and it
 * lives in IssuePlaybackGrant.
 *
 * ⛔ A LESSON'S FILE IS ALSO ASKED THE ASSISTANT SCOPE (spec 010 · FR-005).
 * Until 2026-09-29 every method below asked the permission and the workspace
 * alone, so an assistant confined to one course could upload, complete,
 * replace, re-caption, flip and (with `lessons.delete`) delete the file of a
 * lesson in any other course of the workspace — while `CoursePolicy::update()`
 * and `manageLessons()` refused them that course. `view` is scoped too because
 * `POST /media/assets/{asset}/complete` — a write — is authorised by it.
 *
 * ⛔ AND SO IS A CLASS RECORDING STILL OWNED BY ITS SESSION. Until
 * `PublishRecordingAsLesson` hands it to a lesson, the asset's owner is the
 * `ClassSession`, and the scope asks that session's course through
 * {@see SessionCourseDirectory} — Media never names LiveSessions' models. A
 * session with no course is outside every confinement, as an exam set for no
 * course is: a confined assistant is refused, everybody else untouched. Any
 * other owner (a chat attachment) never reaches the scope question.
 */
class MediaAssetPolicy
{
    public function create(User $user, Lesson $lesson): bool
    {
        return $user->can(Permissions::LESSONS_MANAGE)
            && $this->belongsToUsersWorkspace($user, (int) $lesson->workspace_id)
            && $this->withinAssistantScope($user, (int) $lesson->workspace_id, (int) $lesson->course_id);
    }

    public function view(User $user, MediaAsset $asset): bool
    {
        return $user->can(Permissions::LESSONS_MANAGE)
            && $this->belongsToUsersWorkspace($user, (int) $asset->workspace_id)
            && $this->assetWithinAssistantScope($user, $asset);
    }

    /**
     * Changing the view-only switch is editing lesson content, not destroying
     * it — LESSONS_MANAGE, the same as uploading the file in the first place.
     * Requiring LESSONS_DELETE would mean an assistant who may replace a
     * worksheet may not decide whether it downloads.
     */
    public function update(User $user, MediaAsset $asset): bool
    {
        return $user->can(Permissions::LESSONS_MANAGE)
            && $this->belongsToUsersWorkspace($user, (int) $asset->workspace_id)
            && $this->assetWithinAssistantScope($user, $asset);
    }

    public function delete(User $user, MediaAsset $asset): bool
    {
        return $user->can(Permissions::LESSONS_DELETE)
            && $this->belongsToUsersWorkspace($user, (int) $asset->workspace_id)
            && $this->assetWithinAssistantScope($user, $asset);
    }

    /**
     * Belt and braces alongside the global scope: a permission check alone would
     * pass for a teacher in another workspace who holds the same role.
     */
    private function belongsToUsersWorkspace(User $user, int $workspaceId): bool
    {
        return $user->workspaces()->where('workspaces.id', $workspaceId)->exists();
    }

    /**
     * The scope question for an existing asset: a lesson's file and a recording
     * still owned by its session have a course to ask it about; any other owner
     * (a chat thread, a store product) has its own doors and is refused here.
     *
     * Both owners are read with the workspace scope bypassed on purpose — the
     * membership check above already pinned the workspace, and a scoped read
     * that came back empty under a mismatched context would turn into a `null`
     * course, which refuses a confined assistant their OWN course's file. An
     * owner that is gone is exactly that `null`, and there the contract refuses
     * a confined assistant and passes everybody else.
     */
    private function assetWithinAssistantScope(User $user, MediaAsset $asset): bool
    {
        $ownerType = (string) $asset->owner_type;
        $sessions = app(SessionCourseDirectory::class);

        if ($ownerType === Lesson::class) {
            $courseId = Lesson::query()
                ->withoutWorkspaceScope()
                ->whereKey((int) $asset->owner_id)
                ->value('course_id');
            $courseId = $courseId === null ? null : (int) $courseId;
        } elseif ($sessions->isSessionOwner($ownerType)) {
            $courseId = $sessions->courseIdForSession((int) $asset->owner_id);
        } else {
            /*
            | ⛔ ANY OTHER OWNER HAS ITS OWN DOORS, AND THESE ARE NOT THEM. A chat
            | attachment completes through its thread and a store product's file
            | through `/store/items/{item}/file` (`StoreItemPolicy`). This used to
            | answer `true`, which let `lessons.manage` alone flip a PAID book to
            | downloadable or delete it from under its buyers — with no scope and
            | no store permission asked (2026-10-01 review).
            */
            return false;
        }

        return $this->withinAssistantScope($user, (int) $asset->workspace_id, $courseId);
    }

    /**
     * Spec 010 · FR-005 — asked BESIDE the permission, never instead of it. True
     * for everybody who is not a confined assistant here, the same no-op
     * `CoursePolicy::withinAssistantScope()` is on a workspace with no team.
     */
    private function withinAssistantScope(User $user, int $workspaceId, ?int $courseId): bool
    {
        return app(AssistantScopeDirectory::class)->mayActOnCourse($user, $workspaceId, $courseId);
    }
}
