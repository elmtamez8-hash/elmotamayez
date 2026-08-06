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
            // Seeing the sessions they may book, and their own schedule. Reading
            // one's own attendance needs no permission — it is row ownership.
            Permissions::SESSIONS_VIEW,
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
            // Reads the register; cannot host a room or edit a mark. Scoping
            // assistants properly is spec 010 — until then the narrow grant is
            // the safe default, not the generous one.
            Permissions::ATTENDANCE_VIEW,
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
            Permissions::SESSIONS_MANAGE,
            // Host controls stay with the teacher alone until 010 defines what an
            // assistant may do (FR-016 · research §R14).
            Permissions::SESSIONS_HOST,
            Permissions::ATTENDANCE_OVERRIDE,
            Permissions::FREEZE_MANAGE,
            // The teacher's own contract: ask for a rate, read their statement.
            // Deliberately on $teacher and NOT on $assistantTeacher — an
            // assistant runs the classroom, they do not read the teacher's money
            // (FR-020). Approving a rate, closing a period and executing a payout
            // are platform decisions and reach only super-admin through $all,
            // exactly like MARKETPLACE_TEACHERS_APPROVE.
            Permissions::SETTLEMENT_RATE_REQUEST,
            Permissions::SETTLEMENT_STATEMENT_VIEW,
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
