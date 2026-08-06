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
