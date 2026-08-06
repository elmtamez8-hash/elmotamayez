<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Enums;

/**
 * A rate the teacher asked for, and what the platform decided.
 *
 * Approval is not a formality. The settlement rate is an input to the cost-plus
 * sale price in 006, so approving one moves what every visitor sees — and the
 * person blamed for the drop in conversion would not be the person who changed
 * the number (Q2).
 */
enum RateRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد الاعتماد',
            self::Approved => 'معتمَد',
            self::Rejected => 'مرفوض',
        };
    }
}
