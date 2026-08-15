<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Role;

/**
 * Permission name constants. Seeded into spatie/permission's permissions table.
 * Each permission is a granular action scoped to a resource and operation.
 */
final class Permissions
{
    // Members
    public const MEMBERS_VIEW = 'members.view';

    public const MEMBERS_INVITE = 'members.invite';

    public const MEMBERS_UPDATE = 'members.update';

    public const MEMBERS_REMOVE = 'members.remove';

    // Courses
    public const COURSES_VIEW = 'courses.view';

    public const COURSES_CREATE = 'courses.create';

    public const COURSES_UPDATE = 'courses.update';

    public const COURSES_DELETE = 'courses.delete';

    public const COURSES_PUBLISH = 'courses.publish';

    public const COURSES_ARCHIVE = 'courses.archive';

    // Lessons
    public const LESSONS_MANAGE = 'lessons.manage';

    public const LESSONS_DELETE = 'lessons.delete';

    public const LESSONS_PROGRESS_COMPLETE_OWN = 'lessons.progress.complete.own';

    // Enrollments
    public const ENROLLMENTS_VIEW_ALL = 'enrollments.view.all';

    public const ENROLLMENTS_VIEW_OWN = 'enrollments.view.own';

    public const ENROLLMENTS_CREATE_MANUAL = 'enrollments.create.manual';

    // Exams
    public const EXAMS_VIEW = 'exams.view';

    public const EXAMS_CREATE = 'exams.create';

    public const EXAMS_UPDATE = 'exams.update';

    public const EXAMS_DELETE = 'exams.delete';

    public const EXAMS_PUBLISH = 'exams.publish';

    /*
     | Spec 008 deliberately reuses this constant for the question bank instead
     | of minting `bank.manage`. Two names for one action would contend for one
     | screen, and every custom role a teacher built before 008 would silently
     | lose the bank. What DID change is who holds it: assistants no longer do,
     | because the same permission now authorises permanent-delete refusal and
     | mandatory tagging on a bank shared across every exam.
     */
    public const QUESTIONS_MANAGE = 'questions.manage';

    /** Browsing and searching the bank without editing it (spec 008). */
    public const BANK_VIEW = 'bank.view';

    // Attempts
    public const ATTEMPTS_VIEW_ALL = 'attempts.view.all';

    public const ATTEMPTS_VIEW_OWN = 'attempts.view.own';

    public const ATTEMPTS_SUBMIT = 'attempts.submit';

    // Certificates
    public const CERTIFICATES_VIEW_ALL = 'certificates.view.all';

    public const CERTIFICATES_VIEW_OWN = 'certificates.view.own';

    public const CERTIFICATES_REGENERATE = 'certificates.regenerate';

    // Orders / Payments
    public const ORDERS_VIEW_ALL = 'orders.view.all';

    public const ORDERS_VIEW_OWN = 'orders.view.own';

    public const ORDERS_CREATE = 'orders.create';

    public const PAYMENTS_APPROVE = 'payments.approve';

    public const PAYMENTS_REJECT = 'payments.reject';

    // CMS
    public const CMS_VIEW = 'cms.view';

    public const CMS_CREATE = 'cms.create';

    public const CMS_UPDATE = 'cms.update';

    public const CMS_DELETE = 'cms.delete';

    public const CMS_PUBLISH = 'cms.publish';

    // Grading (spec 008)
    public const GRADING_PERFORM = 'grading.perform';

    public const GRADING_REVISE = 'grading.revise';

    // Assignments (spec 008)
    public const ASSIGNMENTS_MANAGE = 'assignments.manage';

    public const SUBMISSIONS_GRADE = 'submissions.grade';

    public const ACCOMMODATIONS_MANAGE = 'accommodations.manage';

    public const UNLOCK_RULES_MANAGE = 'unlock_rules.manage';

    // Analytics
    public const ANALYTICS_VIEW = 'analytics.view';

    /*
     | Platform-level: reading item analysis ACROSS teachers (FR-015). Held by no
     | tenant role, which `RolePermissionMatrix::platformPermissions()` derives by
     | subtraction — so leaving it out of every role array is the whole mechanism.
     */
    public const ANALYTICS_CROSS_TEACHER_VIEW = 'analytics.cross_teacher.view';

    // Settings
    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_UPDATE = 'settings.update';

    // Marketplace
    public const MARKETPLACE_TEACHERS_REVIEW = 'marketplace.teachers.review';

    public const MARKETPLACE_TEACHERS_APPROVE = 'marketplace.teachers.approve';

    public const MARKETPLACE_TEACHERS_SUSPEND = 'marketplace.teachers.suspend';

    public const MARKETPLACE_REVIEWS_MODERATE = 'marketplace.reviews.moderate';

    public const MARKETPLACE_COMPLAINTS_MANAGE = 'marketplace.complaints.manage';

    public const MARKETPLACE_PARTICIPATION_MANAGE = 'marketplace.participation.manage';

    // Live sessions
    public const SESSIONS_VIEW = 'sessions.view';

    public const SESSIONS_MANAGE = 'sessions.manage';

    /**
     * Host controls inside the room — mute, remove, end (FR-016).
     *
     * Granted to the teacher alone today. The spec reserves it for assistants
     * too, but scoping assistants is spec 010's work; naming the permission now
     * is what lets that phase add a role assignment instead of a policy.
     */
    public const SESSIONS_HOST = 'sessions.host';

    // Attendance
    public const ATTENDANCE_VIEW = 'attendance.view';

    /**
     * Marking a student present by hand. Deliberately not sufficient on its own
     * past the edit window: OverrideAttendance also requires a higher
     * administrative permission there (FR-022ب). The permission answers "may
     * this role ever override?", the Action answers "still, this late?".
     */
    public const ATTENDANCE_OVERRIDE = 'attendance.override';

    // Freeze periods
    public const FREEZE_MANAGE = 'freeze.manage';

    // Notifications
    public const NOTIFICATIONS_LOGS_VIEW = 'notifications.logs.view';

    public const NOTIFICATIONS_TEMPLATES_MANAGE = 'notifications.templates.manage';

    /**
     * Lets a role reach a student's guardian records *at all*. It is deliberately
     * not sufficient on its own: ParentStudentRelationPolicy also requires an
     * active enrollment in the teacher's own workspace. The permission answers
     * "may this role ever look?", the policy answers "at this student?".
     */
    public const RELATIONS_VIEW_STUDENT = 'relations.view.student';

    /*
    | Settlement (spec 014) — the teacher's own money.
    |
    | Deliberately NOT operational permissions. An assistant teacher may hold
    | every session permission there is and still hold none of these: reading a
    | teacher's statement is reading their contract, not running their classroom
    | (FR-020). The same split that separated SESSIONS_VIEW from ATTENDANCE_VIEW
    | in spec 005 — "you may see your sessions" is a different question from
    | "you may read a record about people".
    */

    /** Ask for a new settlement rate. The teacher's own; it does not take effect. */
    public const SETTLEMENT_RATE_REQUEST = 'settlement.rate.request';

    /** Approve or reject one. Platform-level: approving changes the sale price. */
    public const SETTLEMENT_RATE_APPROVE = 'settlement.rate.approve';

    /** Read one's own statement and ledger. */
    public const SETTLEMENT_STATEMENT_VIEW = 'settlement.statement.view';

    /** Close a settlement period and freeze its totals. */
    public const SETTLEMENT_PERIOD_MANAGE = 'settlement.period.manage';

    /** Record a payout against a closed period. */
    public const SETTLEMENT_PAYOUT_EXECUTE = 'settlement.payout.execute';

    /**
     * See both sides of the audit trail.
     *
     * Granted to nobody by default, not even the workspace owner: FR-034 filters
     * the trail so neither party sees the other's side, and this is the one key
     * that lifts the filter. A default grant would make the filter decorative.
     */
    public const SETTLEMENT_AUDIT_VIEW = 'settlement.audit.view';

    /*
    | Billing (spec 006) — the student's money.
    |
    | Read the "who" column of contracts/api.md §1 before adding a grant here.
    | SIX of these eight are PLATFORM permissions and must never reach a tenant
    | role by default: approving a credit purchase, granting a bonus, and raising
    | a credit limit each create a claim on money with no payment leg behind it,
    | and Q-4 made the platform — not the teacher — the seller who carries the
    | bad-debt risk. The teacher being the party paid out of those credits is
    | precisely why they cannot be the party who mints them.
    |
    | The sixth is BILLING_SETTINGS_MANAGE, and it moved here after 006 shipped:
    | switching a workspace to deferred collection is the same decision at
    | wholesale, since 014 pays the teacher from DELIVERY and leaves the platform
    | holding the debt. Only BILLING_BALANCE_VIEW and BILLING_EXAM_MODE_MANAGE
    | remain tenant-side — reading your own students, and a date range on your own
    | calendar.
    */

    /** Read a student's balance *in credits* and their withheld state. Teacher-side. */
    public const BILLING_BALANCE_VIEW = 'billing.balance.view';

    /** Switch the workspace billing mode and its thresholds. Workspace owner. */
    public const BILLING_SETTINGS_MANAGE = 'billing.settings.manage';

    /**
     * Approve an order of kind `credits`.
     *
     * Separate from PAYMENTS_APPROVE on purpose. That one sits inside the
     * teacher array in RolePermissionMatrix, and OrderPolicy::approve accepts it
     * with a workspace check the teacher satisfies by definition — so without
     * this split a teacher marks a transfer that never happened as approved,
     * credits are minted, the session is delivered, and spec 014 pays them for
     * it. Context isolation makes that undetectable from the settlement side.
     */
    public const BILLING_PURCHASE_APPROVE = 'billing.purchase.approve';

    /** Grant a bonus or write a correcting adjustment. Platform-level. */
    public const BILLING_CREDITS_ADJUST = 'billing.credits.adjust';

    /** Raise or lower a student's negative-balance ceiling. Platform-level. */
    public const BILLING_LIMIT_MANAGE = 'billing.limit.manage';

    /** Open and close an exam-mode window over the teacher's own workspace. */
    public const BILLING_EXAM_MODE_MANAGE = 'billing.exam_mode.manage';

    /**
     * Define credit packages.
     *
     * Platform-owned reference data under constitution v1.2.0 §I: the row has no
     * individual owner, so write permission is its only guard. FR-016 puts the
     * packages with the platform because cost-plus pricing is the platform's,
     * and a package the teacher owns is a sale price the teacher sets.
     */
    public const BILLING_PACKAGES_MANAGE = 'billing.packages.manage';

    /** Operating fee and gateway rate — the private half of the price. */
    public const BILLING_PRICING_MANAGE = 'billing.pricing.manage';

    /**
     * Read what was collected across the platform, and what the sweep could not
     * resolve (FR-030 · FR-033).
     *
     * ⚠️ PLATFORM-LEVEL AND HELD BY NO TENANT ROLE — including the workspace
     * owner, who is the person this most needs keeping from. The collection
     * report is every student's payment across every teacher; a teacher who
     * could read it could derive another teacher's rate from two package prices,
     * which is the one number FR-021ب keeps off every surface. It is deliberately
     * absent from every array in RolePermissionMatrix: `all()` reaches the super
     * admin alone, and that is the whole assignment.
     */
    public const BILLING_COLLECTION_VIEW = 'billing.collection.view';

    /**
     * Read the financial audit trail — who decided what, when, and from where
     * (FR-027 · FR-029).
     *
     * ⚠️ `billing.*` AND NOT `payments.*`, WHICH IS NOT COSMETIC. The `payments.*`
     * family is TENANT-scoped in this product: `payments.approve` sits in the
     * teacher's array, so a permission named `payments.audit.view` would be read
     * — by the next person adding a role — as belonging beside it. This one is
     * platform-level and reaches super-admin alone through `all()`.
     *
     * Held by no tenant role for the same reason the collection report is: the
     * trail spans every workspace, and it names the people who took each
     * decision.
     */
    public const BILLING_AUDIT_VIEW = 'billing.audit.view';

    /**
     * Edit the workspace's own roles — who may tick a permission onto one.
     *
     * ⚠️ TENANT-LEVEL, AND THAT IS SAFE ONLY BECAUSE OF WHAT THE SCREEN CANNOT
     * OFFER. The picker's vocabulary is `RolePermissionMatrix::tenantPermissions()`
     * and {@see Role} refuses a platform permission
     * on a workspace role whatever the request says — so the worst an owner can
     * do with this is rearrange authority they already hold. Without both of
     * those, this constant would be "grant yourself anything" under a modest name.
     */
    public const ROLES_MANAGE = 'roles.manage';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::ROLES_MANAGE,
            self::MEMBERS_VIEW,
            self::MEMBERS_INVITE,
            self::MEMBERS_UPDATE,
            self::MEMBERS_REMOVE,
            self::COURSES_VIEW,
            self::COURSES_CREATE,
            self::COURSES_UPDATE,
            self::COURSES_DELETE,
            self::COURSES_PUBLISH,
            self::COURSES_ARCHIVE,
            self::LESSONS_MANAGE,
            self::LESSONS_DELETE,
            self::LESSONS_PROGRESS_COMPLETE_OWN,
            self::ENROLLMENTS_VIEW_ALL,
            self::ENROLLMENTS_VIEW_OWN,
            self::ENROLLMENTS_CREATE_MANUAL,
            self::EXAMS_VIEW,
            self::EXAMS_CREATE,
            self::EXAMS_UPDATE,
            self::EXAMS_DELETE,
            self::EXAMS_PUBLISH,
            self::QUESTIONS_MANAGE,
            self::ATTEMPTS_VIEW_ALL,
            self::ATTEMPTS_VIEW_OWN,
            self::ATTEMPTS_SUBMIT,
            self::CERTIFICATES_VIEW_ALL,
            self::CERTIFICATES_VIEW_OWN,
            self::CERTIFICATES_REGENERATE,
            self::ORDERS_VIEW_ALL,
            self::ORDERS_VIEW_OWN,
            self::ORDERS_CREATE,
            self::PAYMENTS_APPROVE,
            self::PAYMENTS_REJECT,
            self::CMS_VIEW,
            self::CMS_CREATE,
            self::CMS_UPDATE,
            self::CMS_DELETE,
            self::CMS_PUBLISH,
            self::ANALYTICS_VIEW,
            self::SETTINGS_VIEW,
            self::SETTINGS_UPDATE,
            self::MARKETPLACE_TEACHERS_REVIEW,
            self::MARKETPLACE_TEACHERS_APPROVE,
            self::MARKETPLACE_TEACHERS_SUSPEND,
            self::MARKETPLACE_REVIEWS_MODERATE,
            self::MARKETPLACE_COMPLAINTS_MANAGE,
            self::MARKETPLACE_PARTICIPATION_MANAGE,
            self::SESSIONS_VIEW,
            self::SESSIONS_MANAGE,
            self::SESSIONS_HOST,
            self::ATTENDANCE_VIEW,
            self::ATTENDANCE_OVERRIDE,
            self::FREEZE_MANAGE,
            self::NOTIFICATIONS_LOGS_VIEW,
            self::NOTIFICATIONS_TEMPLATES_MANAGE,
            self::RELATIONS_VIEW_STUDENT,
            self::SETTLEMENT_RATE_REQUEST,
            self::SETTLEMENT_RATE_APPROVE,
            self::SETTLEMENT_STATEMENT_VIEW,
            self::SETTLEMENT_PERIOD_MANAGE,
            self::SETTLEMENT_PAYOUT_EXECUTE,
            self::SETTLEMENT_AUDIT_VIEW,
            self::BILLING_BALANCE_VIEW,
            self::BILLING_SETTINGS_MANAGE,
            self::BILLING_PURCHASE_APPROVE,
            self::BILLING_CREDITS_ADJUST,
            self::BILLING_LIMIT_MANAGE,
            self::BILLING_EXAM_MODE_MANAGE,
            self::BILLING_PACKAGES_MANAGE,
            self::BILLING_PRICING_MANAGE,
            // ⚠️ A constant outside `all()` is never seeded, so nothing holds it
            // and every check against it fails — for the super admin too.
            self::BILLING_COLLECTION_VIEW,
            self::BILLING_AUDIT_VIEW,
            // Spec 008. The same rule as the two lines above applies to every one
            // of these — including ANALYTICS_CROSS_TEACHER_VIEW, which no tenant
            // role holds: unseeded, even the super admin's check would fail.
            self::BANK_VIEW,
            self::GRADING_PERFORM,
            self::GRADING_REVISE,
            self::ASSIGNMENTS_MANAGE,
            self::SUBMISSIONS_GRADE,
            self::ACCOMMODATIONS_MANAGE,
            self::UNLOCK_RULES_MANAGE,
            self::ANALYTICS_CROSS_TEACHER_VIEW,
        ];
    }
}
