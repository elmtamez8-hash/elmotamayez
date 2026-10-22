<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Actions\Action;
use DomainException;

/**
 * A teacher who JOINED somebody else's workspace by invitation applies for a
 * profile of their own (co-teacher journey 2026-10-22, owner decision «ب»).
 *
 * Until now the only door was `/auth/register/teacher/step-1`, guest-only and
 * creating a NEW account and workspace — so an invited teacher had no profile,
 * no way to get one, and every course they made was refused the marketplace as
 * `teacher_not_listed`.
 *
 * ⚠️ THE APPLICATION — AND SO THE PROFILE `SubmitTeacherApplication` CREATES
 * FROM IT — LIVES IN THE INVITING WORKSPACE, never a new one. That is the
 * workspace of their courses, so pricing (`CourseTeacherProfile::resolve()`,
 * rates read `forWorkspace(course)`), settlement and listing (`is_publicly_listed`
 * from the profile's workspace) all read the same place. A profile in a fresh
 * workspace of their own would split them (Marketplace audit 2026-10-22).
 *
 * The co-teacher is paid for their own sessions once approved (owner decision
 * 2026-10-22); `RepointCoTeacherCourses` moves their courses on approval.
 *
 * Idempotent: a draft already started is returned, not duplicated —
 * `teacher_applications.user_id` is unique.
 */
final class StartCoTeacherApplication extends Action
{
    public function handle(User $user, Workspace $workspace): TeacherApplication
    {
        $existing = TeacherApplication::query()
            ->withoutWorkspaceScope()
            ->where('user_id', $user->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if (TeacherProfile::query()->withoutWorkspaceScope()->withTrashed()->where('user_id', $user->getKey())->exists()) {
            throw new DomainException('لهذا الحساب ملفّ تدريسي بالفعل.');
        }

        $role = $workspace->members()->whereKey($user->getKey())->first()?->getRelationValue('pivot')?->getAttribute('role');

        if ($role !== Roles::TEACHER) {
            throw new DomainException('التقديم على ملفّ تدريسي من هنا للمدرّس الذي انضمّ بدعوة إلى مكان غيره.');
        }

        $application = new TeacherApplication;
        $application->forceFill([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'status' => TeacherApplication::STATUS_DRAFT,
            // Step 1 is the account form, and the account exists.
            'current_step' => 2,
        ])->save();

        return $application;
    }
}
