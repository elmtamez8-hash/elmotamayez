<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Enums;

/**
 * How completely a third-party processor can honour an erasure (FR-024).
 *
 * ⚠️ RECORDED PER PROCESSOR BECAUSE THE ANSWER GENUINELY DIFFERS, and stating one
 * blanket answer would be a claim to the subject that is false for at least one of
 * them. A media host deletes a video when told; an edge cache expires on its own
 * schedule and can be asked for nothing.
 */
enum ErasureCapability: string
{
    /** Deletes on request, and the deletion is verifiable. */
    case Full = 'full';

    /** Deletes the primary copy; derived or cached copies lapse on their own. */
    case Partial = 'partial';

    /** Cannot be told to forget anything — retention is theirs alone. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'يحذف عند الطلب',
            self::Partial => 'يحذف الأصل، والنسخُ المشتقّة تنقضي بمدّتها',
            self::None => 'لا يستقبل طلبَ حذف',
        };
    }
}
