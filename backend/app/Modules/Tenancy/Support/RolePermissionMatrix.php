<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

/**
 * Maps roles to their granted permissions, per the Permission Matrix.
 * Used by the seeder to sync role-permission assignments.
 */
final class RolePermissionMatrix
{
    /** @return array<string, list<string>> */
    public static function map(): array
    {
        $all = Permissions::all();

        $student = [
            Permissions::COURSES_VIEW,
            Permissions::ENROLLMENTS_VIEW_OWN,
            Permissions::LESSONS_PROGRESS_COMPLETE_OWN,
            Permissions::ATTEMPTS_VIEW_OWN,
            Permissions::ATTEMPTS_SUBMIT,
            Permissions::CERTIFICATES_VIEW_OWN,
            Permissions::ORDERS_VIEW_OWN,
            Permissions::ORDERS_CREATE,
            Permissions::CMS_VIEW,
        ];

        $assistantTeacher = array_merge($student, [
            Permissions::MEMBERS_VIEW,
            Permissions::COURSES_CREATE,
            Permissions::COURSES_UPDATE,
            Permissions::LESSONS_MANAGE,
            Permissions::ENROLLMENTS_VIEW_ALL,
            Permissions::ENROLLMENTS_CREATE_MANUAL,
            Permissions::EXAMS_VIEW,
            Permissions::EXAMS_CREATE,
            Permissions::EXAMS_UPDATE,
            Permissions::QUESTIONS_MANAGE,
            Permissions::ATTEMPTS_VIEW_ALL,
            Permissions::CERTIFICATES_VIEW_ALL,
            Permissions::CMS_CREATE,
            Permissions::CMS_UPDATE,
            Permissions::ANALYTICS_VIEW,
            // Reaching a student's guardians is gated twice: this permission, and
            // an active enrollment in this workspace (ParentStudentRelationPolicy).
            // The relations table is platform-owned and carries no workspace_id,
            // so nothing else stops a teacher from reading another teacher's rows.
            Permissions::RELATIONS_VIEW_STUDENT,
        ]);

        $teacher = array_merge($assistantTeacher, [
            Permissions::COURSES_DELETE,
            Permissions::COURSES_PUBLISH,
            Permissions::COURSES_ARCHIVE,
            Permissions::LESSONS_DELETE,
            Permissions::EXAMS_DELETE,
            Permissions::EXAMS_PUBLISH,
            Permissions::CERTIFICATES_REGENERATE,
            Permissions::ORDERS_VIEW_ALL,
            Permissions::PAYMENTS_APPROVE,
            Permissions::PAYMENTS_REJECT,
            Permissions::CMS_DELETE,
            Permissions::CMS_PUBLISH,
        ]);

        $tenantOwner = array_merge($teacher, [
            Permissions::MEMBERS_INVITE,
            Permissions::MEMBERS_UPDATE,
            Permissions::MEMBERS_REMOVE,
            Permissions::SETTINGS_VIEW,
            Permissions::SETTINGS_UPDATE,
        ]);

        return [
            Roles::SUPER_ADMIN => $all,
            Roles::TENANT_OWNER => $tenantOwner,
            Roles::TEACHER => $teacher,
            Roles::ASSISTANT_TEACHER => $assistantTeacher,
            Roles::STUDENT => $student,
        ];
    }
}
