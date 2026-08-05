<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

enum RelationStatus: string
{
    case Pending = 'pending';
    case Active = 'active';

    /**
     * Revoked, not deleted. Notification delivery stops immediately (FR-023),
     * but the archive of what was already sent stays readable — a guardian who
     * was removed still received those messages, and pretending otherwise makes
     * the delivery log lie.
     */
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار القبول',
            self::Active => 'نشطة',
            self::Revoked => 'ملغاة',
        };
    }
}
