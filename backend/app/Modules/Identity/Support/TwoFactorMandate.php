<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;

/**
 * Who has to turn two-factor on, and by when (FR-028).
 *
 * The deadline is written down when the privilege is granted rather than
 * computed at read time: an operator who lengthens the grace period should not
 * silently push back a deadline someone was already told about, and the panel
 * needs a date to show.
 *
 * Students and guardians are absent on purpose. Mandating a second factor for
 * an account that can do nothing but watch its own lessons trains people to
 * treat the requirement as noise.
 */
final class TwoFactorMandate
{
    /** @var array<int, string> */
    private const PRIVILEGED_ROLES = [
        Roles::SUPER_ADMIN,
        Roles::TENANT_OWNER,
        Roles::TEACHER,
        Roles::ASSISTANT_TEACHER,
    ];

    public static function isPrivileged(string $role): bool
    {
        return in_array($role, self::PRIVILEGED_ROLES, true);
    }

    /**
     * The grace period has run out and the second factor is still off.
     *
     * ⚠️ MOVED HERE FROM `RequireTwoFactor`, WHICH NOW CALLS IT — not copied. The
     * panel needs the identical answer for the identical operation: `/admin` is
     * session-authenticated, so approving a transfer there never passed through
     * the `2fa.required` middleware the two API routes carry, and a second
     * spelling would have put one answer at the button and another at the route.
     *
     * ⚠️ NO ROW AND NO DEADLINE MEAN «NOT OVERDUE», NOT «NOT ENROLLED». Overdue
     * is a PAST deadline on an account that has not enrolled, and nothing else.
     * The super admin had no `user_security_settings` row at all when this was
     * written — read this arm backwards and the platform's only approver is
     * locked out of every approval the moment it deploys, over a grace period
     * they were never given.
     */
    public static function isOverdue(User $user): bool
    {
        if ($user->hasTwoFactorEnabled()) {
            return false;
        }

        $deadline = $user->securitySettings?->two_factor_required_at;

        return $deadline !== null && $deadline->isPast();
    }

    /**
     * The sentence to show, or null when there is nothing to refuse.
     *
     * ⚠️ THE SAME WORDS THE API ANSWERS WITH, and it is shown rather than used to
     * hide the control. A button that silently disappears reads as something
     * broken; this repository's own rule is that a menu item nobody may open is
     * worse than a missing one — the fix is to say why, and to name the screen
     * that fixes it.
     */
    public static function refusalFor(User $user): ?string
    {
        return self::isOverdue($user)
            ? 'انتهت مهلة تفعيل التحقق بخطوتين. فعّله من إعدادات الأمان لمتابعة هذه العملية.'
            : null;
    }

    /**
     * Idempotent: a teacher who joins a second workspace keeps the first
     * deadline instead of being handed a fresh grace period each time.
     */
    public static function applyTo(User $user): void
    {
        $settings = $user->securitySettings;

        if ($settings?->two_factor_required_at !== null) {
            return;
        }

        $days = (int) PlatformSettings::get('auth.two_factor_grace_days', 14);

        $user->securitySettings()->updateOrCreate([], [
            'two_factor_required_at' => now()->addDays(max(0, $days)),
        ]);

        $user->unsetRelation('securitySettings');
    }
}
