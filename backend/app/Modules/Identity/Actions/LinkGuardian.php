<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Data\LinkGuardianData;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Links a guardian to a student. Replaces AddChild.
 *
 * Two rules live here rather than in validation, because the Action is the shared
 * entrance for the API, seeders and Filament (Constitution II):
 *
 *  - at most one active parent per student (FR-019). It is not a unique index:
 *    the constraint is "one active row", and a partial unique index is written
 *    differently on SQLite and MySQL, so the two environments would disagree
 *    about what the schema enforces.
 *  - a student account can only be attached once by the same guardian.
 */
class LinkGuardian extends Action
{
    public function handle(User $guardian, LinkGuardianData $data): ParentStudentRelation
    {
        $student = $data->studentUuid === null ? null : $this->resolveStudent($data->studentUuid);

        if ($student !== null && $this->alreadyLinked($guardian, $student)) {
            throw new DomainException('هذا الطالب مرتبط بحسابك بالفعل.');
        }

        if ($data->relationType === RelationType::Parent && $student !== null && $this->hasActiveParent($student)) {
            throw new DomainException('لهذا الطالب وليّ أمر مسجَّل. يمكن إضافة وصيّ بدلاً من ذلك.');
        }

        return ParentStudentRelation::query()->create([
            'guardian_user_id' => $guardian->getKey(),
            'student_user_id' => $student?->getKey(),
            'student_name' => $data->studentName,
            'student_age' => $data->studentAge,
            'student_grade_level_slug' => $data->gradeLevelSlug,
            'relation_type' => $data->relationType->value,
            'permissions' => array_map(fn ($permission) => $permission->value, $data->permissions),
            // A guardian added for a child with no account yet is active at once:
            // there is nobody who could accept it. Once the student has an account
            // of their own, the link starts pending until they do.
            'status' => $student === null ? RelationStatus::Active->value : RelationStatus::Pending->value,
        ]);
    }

    /**
     * Attaching an existing account is the one place a guardian names someone
     * else's record, so the rules are strict: it must be a student account, and
     * "not found" and "not a student" answer the same way — otherwise the endpoint
     * confirms which accounts exist on the platform.
     */
    private function resolveStudent(string $uuid): User
    {
        $student = User::query()
            ->where('uuid', $uuid)
            ->where('platform_role', PlatformRole::Student)
            ->first();

        if ($student === null) {
            throw new DomainException('لم نجد حساب طالب بهذا المعرّف.');
        }

        return $student;
    }

    private function alreadyLinked(User $guardian, User $student): bool
    {
        return ParentStudentRelation::query()
            ->where('guardian_user_id', $guardian->getKey())
            ->where('student_user_id', $student->getKey())
            ->exists();
    }

    private function hasActiveParent(User $student): bool
    {
        return ParentStudentRelation::query()
            ->forStudent($student)
            ->where('relation_type', RelationType::Parent->value)
            ->whereIn('status', [RelationStatus::Active->value, RelationStatus::Pending->value])
            ->exists();
    }
}
