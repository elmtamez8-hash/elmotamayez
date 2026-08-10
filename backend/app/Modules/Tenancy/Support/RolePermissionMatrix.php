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
            // Whether a student is blocked and how many credits they hold, in
            // credits and never in money (FR-052). Operational — the assistant
            // who schedules a session needs to know who can book — and it is the
            // opposite of SETTLEMENT_STATEMENT_VIEW below: this is a fact about
            // the student's standing, not about the teacher's contract. Reaching
            // any given student is gated a second time by an active enrollment
            // in this workspace (FR-055), the same double gate as
            // RELATIONS_VIEW_STUDENT.
            Permissions::BILLING_BALANCE_VIEW,
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
            // Opening an exam-mode window over their own workspace: a scheduling
            // decision about their own calendar, so it belongs here.
            //
            // Note what is NOT here, and why PAYMENTS_APPROVE above is no longer
            // enough on its own. Approving a credit purchase, granting a bonus
            // and raising a credit limit each create a claim on money with no
            // payment leg behind it — and spec 014 pays this same teacher out of
            // the credits that get consumed. The party who is paid cannot be the
            // party who mints. Q-4 moved the seller role to the platform; these
            // three are that decision finished. They reach super-admin through
            // $all, exactly like SETTLEMENT_RATE_APPROVE.
            //
            // PAYMENTS_APPROVE stays: it still approves a course order. The
            // split is enforced on `orders.kind` in OrderPolicy::approve, not by
            // taking a working permission away.
            Permissions::BILLING_EXAM_MODE_MANAGE,
        ]);

        $tenantOwner = array_merge($teacher, [
            Permissions::MEMBERS_INVITE,
            Permissions::MEMBERS_UPDATE,
            Permissions::MEMBERS_REMOVE,
            Permissions::SETTINGS_VIEW,
            Permissions::SETTINGS_UPDATE,
            //
            // ⚠️ BILLING_SETTINGS_MANAGE USED TO BE HERE, ARGUED AS "AN OWNERSHIP
            // DECISION ABOUT THE WORKSPACE, NOT A TEACHING ONE". The argument was
            // about the wrong ownership. Since spec 014 the teacher is paid from
            // DELIVERY, not from collection — so the debt a teacher would be
            // permitting on their own students is a debt on the PLATFORM, which
            // carries it until somebody pays. Deferred payment is the platform
            // lending money, and the borrower's teacher does not set the terms.
            //
            // It joins the other five in $all, beside BILLING_LIMIT_MANAGE, which
            // was already platform-only for exactly this reason: a ceiling a
            // teacher could raise is a teacher deciding how much the platform may
            // be owed. Switching the mode is the same decision at wholesale.
            //
        ]);

        return [
            Roles::SUPER_ADMIN => $all,
            Roles::TENANT_OWNER => $tenantOwner,
            Roles::TEACHER => $teacher,
            Roles::ASSISTANT_TEACHER => $assistantTeacher,
            Roles::STUDENT => $student,
            /*
            | The delegated finance officer — one permission, listed literally.
            |
            | Not built from another array: every other row here is `$smaller +
            | extras`, and that shape is right for roles that nest. This one does
            | not nest in anything. Composing it from $teacher or $tenantOwner
            | would hand a finance clerk a classroom, and composing $tenantOwner
            | from it would hand a teacher the approval Q-4 took away.
            */
            Roles::FINANCE_ADMIN => [
                Permissions::BILLING_PURCHASE_APPROVE,
                // What they are approving. Without it the approval screen is a
                // button with no receipt behind it.
                Permissions::ORDERS_VIEW_ALL,
            ],
        ];
    }
}
