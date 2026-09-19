<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * What the platform did with a teacher's request to change a priced plan (٠٣٦).
 *
 * ⚠️ ITS OWN ENUM RATHER THAN `Settlement\Enums\RateRequestStatus`, WHICH READS
 * IDENTICALLY. `ContextIsolationTest` fails the build over a `use App\Modules\
 * Settlement` written under `Modules/Payments/` — the teacher's pay and the
 * student's payment share no key, no query and no screen by design (٠٠٦), and
 * an enum imported across that line is the first thread of the rope.
 */
enum PlanChangeStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد المراجعة',
            self::Approved => 'مقبول',
            self::Rejected => 'مرفوض',
        };
    }
}
