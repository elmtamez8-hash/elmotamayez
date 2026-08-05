<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Delivered = 'delivered';
    case Failed = 'failed';

    /**
     * Not an incident: the channel was disabled, not implemented, excluded by
     * preference, or the relation was revoked before the job ran. Kept distinct
     * from Failed so the delivery log's failure rate means something.
     */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار الإرسال',
            self::Queued => 'في الطابور',
            self::Delivered => 'سُلّم',
            self::Failed => 'فشل',
            self::Skipped => 'تُخطّي',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Failed, self::Skipped => true,
            self::Pending, self::Queued => false,
        };
    }
}
