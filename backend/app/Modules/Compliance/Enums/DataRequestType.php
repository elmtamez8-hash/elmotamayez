<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Enums;

/**
 * What a data-rights request asks for (FR-014 … FR-023).
 *
 * ⚠️ `Access` AND `Export` ARE THE SAME WALK WITH DIFFERENT DELIVERY, and keeping
 * them separate is what stops "let me see it" from silently producing a
 * downloadable archive of a child's whole record every time somebody is curious.
 * One is read on screen; the other is a file that leaves the platform.
 */
enum DataRequestType: string
{
    case Access = 'access';
    case Export = 'export';
    case Erasure = 'erasure';

    public function label(): string
    {
        return match ($this) {
            self::Access => 'الاطّلاع على البيانات',
            self::Export => 'تصدير نسخة من البيانات',
            self::Erasure => 'حذف البيانات',
        };
    }

    /** Whether fulfilling it writes an archive to disk. */
    public function producesArchive(): bool
    {
        return $this === self::Export;
    }
}
