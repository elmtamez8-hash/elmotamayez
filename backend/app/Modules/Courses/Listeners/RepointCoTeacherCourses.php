<?php

declare(strict_types=1);

namespace App\Modules\Courses\Listeners;

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Support\CourseTeacherProfile;
use App\Modules\Marketplace\Events\TeacherApproved;
use App\Shared\Scopes\WorkspaceScope;

/**
 * An approved co-teacher's courses move from the owner's profile to their own
 * (co-teacher journey 2026-10-22, owner decision: the co-teacher is paid for
 * their own work once approved).
 *
 * Their courses were written with the OWNER's profile — `resolve()`'s fallback
 * for a creator with none — so without this the course would be priced on the
 * owner's rate while the sessions they schedule settle on their own profile
 * (`SchedulableTeachers::ownProfile()`): two answers to «whose course».
 *
 * ⚠️ `resolve()` decides, as in {@see ClaimCoursesForNewTeacherProfile}: a row
 * moves only when the creator is THIS teacher and the rule now names this
 * profile — a colleague's course is never touched, and an owner's own approval
 * moves nothing (their courses already carry their profile).
 */
class RepointCoTeacherCourses
{
    public function handle(TeacherApproved $event): void
    {
        $profile = $event->profile;
        $workspaceId = (int) $profile->workspace_id;
        $userId = (int) $profile->user_id;

        if (CourseTeacherProfile::resolve($workspaceId, $userId) !== $profile->getKey()) {
            return;
        }

        Course::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->where('created_by', $userId)
            ->where(fn ($q) => $q->whereNull('teacher_profile_id')->orWhere('teacher_profile_id', '!=', $profile->getKey()))
            ->update(['teacher_profile_id' => $profile->getKey()]);
    }
}
