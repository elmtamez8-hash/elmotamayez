<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

/**
 * Whether an asset IS the lesson's content, or sits beside it.
 *
 * RequestUploadTicket has always assumed one asset per lesson and deletes the
 * existing one before creating the next — correct for the lesson's own video,
 * and wrong the moment a worksheet is attached to it. One column separates the
 * two cases and lets an attachment inherit every protection built in 004
 * without a line of new security code.
 */
enum MediaRole: string
{
    case Primary = 'primary';
    case Attachment = 'attachment';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'محتوى الدرس',
            self::Attachment => 'مرفق',
        };
    }
}
