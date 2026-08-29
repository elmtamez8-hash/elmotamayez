<?php

declare(strict_types=1);

namespace App\Modules\Store\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

/**
 * What kind of thing is being sold, and it decides three different rules.
 *
 * ⚠️ THE BRANCH COMES FIRST, EVERY TIME. `stock` is `null` for a digital item,
 * and `stock >= :qty` against NULL is NULL — so a claim that reads the stock
 * before it reads the kind matches zero rows and reports «نفد المخزون» about a
 * product that cannot run out.
 */
enum StoreItemKind: string implements HasArabicLabel
{
    use BuildsOptions;

    /** A file. Delivered the instant the payment is approved. */
    case Digital = 'digital';

    /** A printed copy. Decrements stock and opens a shipment. */
    case Physical = 'physical';

    public function label(): string
    {
        return match ($this) {
            self::Digital => 'نسخة رقمية',
            self::Physical => 'نسخة مطبوعة',
        };
    }

    /** Whether this kind has a stock count at all. */
    public function isStocked(): bool
    {
        return $this === self::Physical;
    }

    /**
     * Whether buying it needs an address.
     *
     * Asked before the order is written, not at fulfilment: FR-007 refuses the
     * purchase rather than taking the money and discovering later that there is
     * nowhere to send the parcel.
     */
    public function needsAddress(): bool
    {
        return $this === self::Physical;
    }
}
