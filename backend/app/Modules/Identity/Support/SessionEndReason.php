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

    public function label(): string
    {
        return match ($this) {
            self::Logout => 'سجّلت الخروج.',
            self::DeviceLimit => 'سُجّل الدخول إلى حسابك من جهاز آخر.',
            self::PasswordChange => 'تغيّرت كلمة مرور حسابك.',
            self::TwoFactorChange => 'تغيّرت إعدادات التحقق الثنائي لحسابك.',
            self::Manual => 'أُنهيت هذه الجلسة من قائمة أجهزتك.',
            self::Expired => 'انتهت صلاحية الجلسة.',
        };
    }
}
