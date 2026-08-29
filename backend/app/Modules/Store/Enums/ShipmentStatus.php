<?php

declare(strict_types=1);

namespace App\Modules\Store\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

/**
 * How far a parcel has got.
 *
 * ⚠️ EVERY TRANSITION IS CONDITIONAL (`WHERE status = :expected`) AND EVERY
 * TRANSITION NOTIFIES THE BUYER. Read-then-write from two workers tells them
 * twice, or moves the parcel backwards — «تم الشحن» after «تم التسليم», which is
 * a message nobody can un-send.
 */
enum ShipmentStatus: string implements HasArabicLabel
{
    use BuildsOptions;

    case Pending = 'pending';
    case Packed = 'packed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار التجهيز',
            self::Packed => 'جُهِّز',
            self::Shipped => 'في الطريق',
            self::Delivered => 'وصل',
            self::Returned => 'مُرتجَع',
        };
    }

    /**
     * Which state may follow this one.
     *
     * ⚠️ A LIST, NOT A NUMBER. Modelling this as an ordered ladder makes
     * `Returned` either unreachable or reachable from everywhere; it follows a
     * delivery attempt and nothing else.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Packed],
            self::Packed => [self::Shipped],
            self::Shipped => [self::Delivered, self::Returned],
            self::Delivered, self::Returned => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->next() === [];
    }
}
