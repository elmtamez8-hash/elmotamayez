<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Enums;

/**
 * A teacher's exit, step by step (FR-032 … FR-037).
 *
 * ⚠️ `SettlementPending` IS A STATE RATHER THAN A FLAG, because the money is what
 * the whole sequence waits on: nothing may complete while anything is owed in
 * either direction. A boolean beside `requested` would make the wait invisible on
 * the officer's queue, which is the one screen that has to show it.
 */
enum OffboardingStatus: string
{
    case Requested = 'requested';
    case SettlementPending = 'settlement_pending';
    case NoticePeriod = 'notice_period';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'طلبٌ جديد',
            self::SettlementPending => 'بانتظار حسم المستحقّات',
            self::NoticePeriod => 'مهلة الإخطار',
            self::Completed => 'اكتمل الخروج',
        };
    }
}
