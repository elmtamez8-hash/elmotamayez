<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Payments\Enums\CouponValueKind;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Contracts\GuardianDirectory;

/**
 * The family discount (spec 011 · FR-013 · D16 · SC-004).
 *
 * Automatic: no code, no request, no screen. A student who shares a guardian
 * with another registered student is charged the platform's family rate on every
 * purchase, at every teacher.
 *
 * ⚠️ READ THROUGH {@see GuardianDirectory}, NEVER BY QUERYING
 * `parent_student_relations`. That contract's own docblock names this exact
 * breach — «the only alternative, Payments querying `parent_student_relations`
 * itself, is a Constitution III breach on a platform-owned table that carries no
 * workspace scope to fall back on» — and the table has no global scope to catch
 * a mistake, so the boundary is the whole of the protection.
 *
 * ⚠️ ONE VALUE FOR THE WHOLE PLATFORM, and no per-workspace row anywhere. The
 * discount comes out of the platform's own commission, so the platform is what
 * decides it — FR-010 to the letter, «whoever pays is whoever decides». A
 * teacher able to set it would be setting a discount somebody else funds.
 *
 * ⚠️ AND IT IS DISCOVERED FROM THE PROVEN RELATION, NOT FROM A PHONE NUMBER. The
 * spec's first assumption was `users.phone`; that column is a free string nobody
 * confirmed, and this repository has already written down what a typo in it
 * costs — «a message about a child sent to a stranger». Here it would hand one
 * family another family's money, and the spec's own edge case («two children
 * linked to different guardians on one number») disappears rather than needing a
 * rule, because no number is in the equation at all.
 */
class SiblingDiscount
{
    public function __construct(private readonly GuardianDirectory $guardians) {}

    /**
     * The percentage off, or zero when this student does not qualify.
     *
     * Clamped to `[0, 100]`: a stored 150 would pay the buyer to shop, and a
     * negative one would charge them extra under the name of a discount.
     */
    public function percentFor(User $student): int
    {
        $percent = $this->configuredPercent();

        if ($percent === 0) {
            // Off. Asked BEFORE the directory, so the platform that has not
            // turned this on pays for no query at all — this runs on every
            // purchase on the platform.
            return 0;
        }

        return $this->guardians->hasRegisteredSibling($student) ? $percent : 0;
    }

    /**
     * What the family discount takes off one line, in minor units.
     *
     * Routed through {@see CouponValueKind::Percent} rather than repeating the
     * arithmetic: the flooring and the clamp at the line total are one rule, and
     * a second copy of it here is the second answer that drifts.
     */
    public function discountFor(User $student, int $lineTotalMinor): int
    {
        $percent = $this->percentFor($student);

        return $percent === 0
            ? 0
            : CouponValueKind::Percent->discountOn($percent, $lineTotalMinor);
    }

    public function configuredPercent(): int
    {
        $percent = (int) PlatformSettings::get('billing.sibling_discount', config('billing.sibling_discount', 0));

        return max(0, min(100, $percent));
    }
}
