<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

/**
 * Arabic for a role name.
 *
 * ⚠️ IT LIVES BESIDE `Roles`, NOT IN THE BROWSER, AND THE DRIFT ALREADY HAPPENED.
 * `lib/labels.ts` carried a map keyed `owner`, `admin`, `assistant` — words that
 * are not role names in this product. The real ones are `tenant-owner`,
 * `assistant-teacher`, `super-admin`, `finance-admin` and `compliance-officer`,
 * so FIVE of the seven fell through the map's `?? role` fallback and rendered as
 * English slugs on an Arabic-only screen. A second copy of the wording is stale
 * the day the first one changes, which is exactly the rule `lib/notifications.ts`
 * already states for notification types.
 *
 * ⚠️ AND THE FALLBACK IS THE NAME ITSELF, WHICH IS CORRECT HERE RATHER THAN A
 * COMPROMISE. Roles are editable from `/admin` now, so an owner can create one
 * and name it — in Arabic, because they are writing for their own workspace.
 * Returning that name unchanged is the right answer for every role this class
 * has never heard of; a blank would be a badge with nothing in it, and a guessed
 * translation would be worse.
 *
 * `RoleLabelCoverageTest` fails the build if `Roles::all()` gains a constant with
 * no entry here — the one failure mode a fallback that returns something readable
 * would otherwise hide for ever.
 */
final class RoleLabels
{
    /** @var array<string, string> */
    private const LABELS = [
        Roles::SUPER_ADMIN => 'مدير المنصّة',
        Roles::FINANCE_ADMIN => 'مسؤول مالي',
        Roles::COMPLIANCE_OFFICER => 'مسؤول حماية البيانات',
        Roles::TENANT_OWNER => 'مالك المساحة',
        Roles::TEACHER => 'مدرّس',
        Roles::ASSISTANT_TEACHER => 'مدرّس مساعد',
        Roles::STUDENT => 'طالب',
    ];

    public static function for(?string $role): ?string
    {
        if ($role === null) {
            return null;
        }

        return self::LABELS[$role] ?? $role;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::LABELS;
    }
}
