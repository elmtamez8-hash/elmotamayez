<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

/**
 * Role name constants. These are seeded into spatie/permission's roles table.
 * The super-admin and finance-admin roles are global (team_id = null); all
 * others are workspace-scoped.
 */
final class Roles
{
    public const SUPER_ADMIN = 'super-admin';

    /**
     * The delegated finance officer (spec 006).
     *
     * ⚠️ GLOBAL, LIKE SUPER-ADMIN, AND FOR THE SAME REASON. Credit-purchase
     * receipts arrive from every workspace on the platform, so a finance officer
     * scoped to a team would have to be a member of every team — which is a
     * super-admin with extra steps and a longer audit trail.
     *
     * It exists because the two obvious answers are both wrong. Requiring a
     * super-admin for every receipt is a bottleneck with a person in it; handing
     * approval to the teacher makes the party who is PAID the party who MINTS,
     * which is the split Q-4 moved to the platform in the first place.
     *
     * It holds approval and nothing else. A finance role that could also price a
     * package, move a credit ceiling or switch a billing mode would be a second
     * super-admin wearing a narrower name — and each of those three decides how
     * much the PLATFORM may be owed, which is not a clerical act.
     */
    public const FINANCE_ADMIN = 'finance-admin';

    /**
     * The data-protection officer (spec 013).
     *
     * ⚠️ GLOBAL FOR THE SAME REASON AS THE FINANCE OFFICER, AND MORE SO. A rights
     * request returns everything the platform knows about one person, across every
     * teacher they study with — so an officer scoped to a team could only ever
     * fulfil a fraction of one, and "the partial export" is not a right that has
     * been implemented.
     *
     * ⚠️ AND WITHOUT IT `FR-026`'s "who executed this" IS ONE PERSON. The five
     * compliance permissions reach `super-admin` through `Permissions::all()`
     * alone; with no second role there is exactly one account on the platform that
     * can execute an erasure, and the audit column records that account for every
     * request ever made. A record that always names the same person records
     * nothing.
     *
     * It holds the five compliance permissions and nothing else. Not the finance
     * ones — reading a child's whole file and approving a payment are different
     * jobs — and composing either role from the other would hand each the other's.
     */
    public const COMPLIANCE_OFFICER = 'compliance-officer';

    public const TENANT_OWNER = 'tenant-owner';

    public const TEACHER = 'teacher';

    public const ASSISTANT_TEACHER = 'assistant-teacher';

    public const STUDENT = 'student';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SUPER_ADMIN,
            self::FINANCE_ADMIN,
            self::COMPLIANCE_OFFICER,
            self::TENANT_OWNER,
            self::TEACHER,
            self::ASSISTANT_TEACHER,
            self::STUDENT,
        ];
    }

    /** @return list<string> Global roles (team_id = null), which reach every workspace. */
    public static function platformRoles(): array
    {
        return [
            self::SUPER_ADMIN,
            self::FINANCE_ADMIN,
            self::COMPLIANCE_OFFICER,
        ];
    }

    /** @return list<string> Workspace-scoped roles (excludes super-admin). */
    public static function workspaceRoles(): array
    {
        return [
            self::TENANT_OWNER,
            self::TEACHER,
            self::ASSISTANT_TEACHER,
            self::STUDENT,
        ];
    }
}
