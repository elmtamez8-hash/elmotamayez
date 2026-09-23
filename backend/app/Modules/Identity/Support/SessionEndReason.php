<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

/**
 * Why a session stopped. Kept on the row after it ends — this is the audit
 * trail, and it is also what the sign-in screen shows the person who was
 * unexpectedly logged out.
 */
enum SessionEndReason: string
{
    case Logout = 'logout';
    case DeviceLimit = 'device_limit';
    case PasswordChange = 'password_change';
    case TwoFactorChange = 'two_factor_change';
    case Manual = 'manual';
    case Expired = 'expired';

    /**
     * The workspace this account taught in was wound down (013 · FR-037).
     *
     * ⚠️ NOT `Manual`, WHOSE SENTENCE IS FALSE HERE — it says the session was ended
     * from the person's own devices list, which is the one screen an offboarded
     * teacher did not touch. This label is what the sign-in page shows them, and a
     * wrong reason there is worse than a generic one.
     */
    case Offboarding = 'offboarding';

    /**
     * Nobody used the session for `auth.session_idle_days` (spec: owner decision
     * 2026-09-23, thirty days by default).
     *
     * ⚠️ NOT `Expired`, whose sentence reads as a fault. This one says WHY — a
     * long silence — so the person reading it on the sign-in screen knows nothing
     * happened to their account and signing in again is all it takes.
     */
    case Idle = 'idle';

    public function label(): string
    {
        return match ($this) {
            self::Logout => 'سجّلت الخروج.',
            self::DeviceLimit => 'سُجّل الدخول إلى حسابك من جهاز آخر.',
            self::PasswordChange => 'تغيّرت كلمة مرور حسابك.',
            self::TwoFactorChange => 'تغيّرت إعدادات التحقق الثنائي لحسابك.',
            self::Manual => 'أُنهيت هذه الجلسة من قائمة أجهزتك.',
            self::Expired => 'انتهت صلاحية الجلسة.',
            self::Offboarding => 'اكتمل خروجك من مكان العمل.',
            self::Idle => 'انتهت الجلسة لأنها لم تُستخدم مدّة طويلة.',
        };
    }
}
