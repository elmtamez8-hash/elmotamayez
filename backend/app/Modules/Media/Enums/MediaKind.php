<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

/**
 * What kind of file an asset holds.
 *
 * Added in 016 because `pdf` and `file` have been declared lesson types since
 * the first migration with no upload path at all: the pipeline accepted video
 * and only video, and the rejection message said so out loud. The storage
 * mechanism did not need replacing — MediaAsset is already polymorphic and the
 * local provider already streams by range request, which is exactly what a PDF
 * needs. What was missing is this classification, and the per-kind mime lists
 * and limits that hang off it.
 */
enum MediaKind: string
{
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Video => 'فيديو',
            self::Audio => 'صوت',
            self::Document => 'مستند',
        };
    }

    /** Rejection text names the kind — "not a supported video" on a PDF upload is a wrong answer. */
    public function rejectionMessage(): string
    {
        return match ($this) {
            self::Video => 'الملف المرفوع ليس ملف فيديو مدعوماً.',
            self::Audio => 'الملف المرفوع ليس ملفاً صوتياً مدعوماً.',
            self::Document => 'الملف المرفوع ليس مستنداً مدعوماً.',
        };
    }

    /** Only these carry a meaningful duration; a PDF has none to read. */
    public function hasDuration(): bool
    {
        return $this === self::Video || $this === self::Audio;
    }
}
