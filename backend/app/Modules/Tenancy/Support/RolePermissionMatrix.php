<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Role;

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
            // ⚠️ QUESTIONS_MANAGE USED TO BE HERE, and spec 008 took it away.
            // It authorised editing questions inside one exam; after 008 the same
            // permission governs a BANK shared across every exam of the workspace,
            // where a delete is refused in favour of a disable and every question
            // carries four mandatory tags. Widening what a permission means while
            // leaving its holders alone is how an assistant silently inherits the
            // teacher's whole question library. BANK_VIEW below is the read half
            // they do keep — browsing and searching, which is what an assistant
            // building a lesson actually needs.
            Permissions::BANK_VIEW,
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
            //
            // ⚠️ BILLING_BALANCE_VIEW USED TO BE HERE, AND SPEC 010 MOVED IT DOWN
            // TO $teacher — MOVED, never deleted. 010's FR-003 refuses an
            // assistant every financial surface there is, and this was the one
            // financial permission an assistant held by default. The 006
            // argument for it — "operational; the assistant who schedules needs
            // to know who can book" — is still true, which is exactly why it
            // moves rather than disappearing: the owner can tick it back onto a
            // custom assistant role from the roles screen, deliberately, for a
            // named person.
            //
            // ⚠️ AND DELETING IT OUTRIGHT WOULD HAVE BROKEN THE SEEDER, not just
            // this role. These arrays compose upward, so a name removed here is
            // removed from $teacher and $tenantOwner too — and a permission no
            // tenant role holds becomes PLATFORM-level by derivation in
            // platformPermissions(), after which {@see Role} throws the moment
            // SeedDefaultRoles grants it. The failure is `php artisan db:seed`
            // dying, not a review comment.
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
            // Spec 008. All seven sit on $teacher and NOT on $assistantTeacher,
            // and that placement IS the delivery channel for FR-031: the matrix
            // seeds a default, the roles screen lets the owner tick any of them
            // onto a custom assistant role. Seeding grading onto every assistant
            // by default would read "the assistant may grade if granted" as "the
            // assistant grades", which is the opposite requirement.
            Permissions::QUESTIONS_MANAGE,
            Permissions::GRADING_PERFORM,
            Permissions::GRADING_REVISE,
            Permissions::ASSIGNMENTS_MANAGE,
            Permissions::SUBMISSIONS_GRADE,
            Permissions::ACCOMMODATIONS_MANAGE,
            Permissions::UNLOCK_RULES_MANAGE,
            /*
            | Spec 009 — the teacher's half of gamification, and only that half.
            |
            | Their shop, their fulfilment queue, and reading the progress of a
            | student enrolled with them. On $teacher rather than
            | $assistantTeacher for the same reason grading is: the owner can tick
            | any of them onto a custom assistant role, and seeding them by
            | default would read "the assistant may fulfil if granted" as "the
            | assistant fulfils".
            |
            | ⚠️ AND LISTING THEM HERE IS LOAD-BEARING, NOT COSMETIC.
            | platformPermissions() is derived by SUBTRACTION, so a constant left
            | out of every array below is platform-level BY DERIVATION — and then
            | {@see Role} throws the moment the seeder grants it to a role with a
            | team_id. The failure is not a review comment, it is
            | `php artisan db:seed` dying.
            |
            | ⚠️ GAMIFICATION_CATALOG_MANAGE AND TAXONOMY_MANAGE ARE DELIBERATELY
            | ABSENT, HERE AND EVERYWHERE ELSE IN THIS FILE. Adding either one
            | hands a teacher the ability to set what every action on the platform
            | is worth, or to rewrite the subject list for every other teacher.
            */
            Permissions::REWARDS_MANAGE,
            Permissions::REDEMPTIONS_FULFILL,
            Permissions::PROGRESS_VIEW_STUDENT,
            /*
            | Spec 010 — the teacher's team.
            |
            | BILLING_BALANCE_VIEW arrives here from $assistantTeacher (see the
            | note there): whether a student is blocked and how many credits they
            | hold, in credits and never in money. It is the teacher's to read and
            | the owner's to delegate, one assistant at a time.
            |
            | CHAT_REPLY is new, and it is here for the same reason grading is —
            | the placement IS the delivery channel for FR-002. An assistant who
            | should answer students gets a custom role with this box ticked; one
            | who should not, does not.
            */
            Permissions::BILLING_BALANCE_VIEW,
            Permissions::CHAT_REPLY,
            /*
            | CHAT_MODERATE is separate from CHAT_REPLY on purpose: answering a
            | student and silencing one are different powers over the same
            | people, and a single constant would make every assistant who may
            | reply a moderator too. Both here, neither on $assistantTeacher —
            | the owner delegates each for a named person.
            */
            Permissions::CHAT_MODERATE,
            //
            // ⚠️ ANALYTICS_CROSS_TEACHER_VIEW IS DELIBERATELY ABSENT, HERE AND IN
            // EVERY OTHER ARRAY IN THIS FILE. platformPermissions() is derived by
            // SUBTRACTION — all() minus everything any tenant role holds — so the
            // absence is not an oversight to be corrected later, it is the whole
            // mechanism. Adding it to any array below silently hands one teacher
            // the error rates of every other teacher on the platform.
        ]);

        $tenantOwner = array_merge($teacher, [
            // The owner, and nobody below them, rearranges their own roles. It is
            // the same authority as inviting and removing people, expressed once
            // instead of per member — and it can only ever move permissions the
            // owner already holds, because the picker offers no others and the
            // model refuses the rest.
            Permissions::ROLES_MANAGE,
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
            /*
            | The data-protection officer (spec 013) — listed literally, for the
            | reason above and one of its own.
            |
            | ⚠️ IT COMPOSES FROM NOTHING AND NOTHING COMPOSES FROM IT. Adding the
            | finance permissions would let one person read a child's whole file
            | AND approve the payment behind it; adding these to the finance role
            | would hand a payments clerk every minor's export. Two jobs, two
            | rows, no overlap.
            |
            | ⚠️ AND IT EXISTS SO `FR-026` RECORDS SOMETHING. Without a second
            | holder, the five permissions reach `super-admin` alone through
            | `Permissions::all()` — one account on the platform, named as the
            | executor of every request ever made.
            */
            Roles::COMPLIANCE_OFFICER => [
                Permissions::COMPLIANCE_REQUESTS_EXECUTE,
                Permissions::COMPLIANCE_REGISTRY_MANAGE,
                Permissions::COMPLIANCE_HOLDS_MANAGE,
                Permissions::COMPLIANCE_OFFBOARDING_EXECUTE,
                Permissions::COMPLIANCE_BREACHES_MANAGE,
            ],
        ];
    }

    /**
     * The permissions no tenant role holds.
     *
     * ⚠️ DERIVED, NEVER LISTED. A second array naming the platform's permissions
     * is a second place to forget one, and the one forgotten is the one that
     * matters: a permission absent from that list becomes tickable on a
     * workspace role, and a teacher grants themselves the platform. The
     * subtraction below cannot fall behind, because it reads the same arrays the
     * seeder does — a new permission is platform-level until somebody puts it in
     * a tenant role ON PURPOSE.
     *
     * This is what {@see Role} refuses to attach
     * to a workspace-scoped role, and what the role screen never offers.
     *
     * @return list<string>
     */
    public static function platformPermissions(): array
    {
        $map = self::map();
        $held = [];

        foreach (Roles::workspaceRoles() as $role) {
            $held = array_merge($held, $map[$role] ?? []);
        }

        return array_values(array_diff(Permissions::all(), $held));
    }

    /**
     * The permissions a workspace role may hold — the complement of the above.
     *
     * @return list<string>
     */
    public static function tenantPermissions(): array
    {
        return array_values(array_diff(Permissions::all(), self::platformPermissions()));
    }
}
