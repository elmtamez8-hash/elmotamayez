<?php

declare(strict_types=1);

namespace App\Modules\Courses\Enums;

/**
 * What a content item in the course tree is.
 *
 * Ten values in four families, and the family is what decides where the content
 * actually lives — written on the row, uploaded as an asset, a reference to
 * another entity, or an address outside the platform. `LessonTypeRegistry` owns
 * that mapping; this enum owns only the vocabulary and its Arabic labels.
 *
 * `Assignment` is declared and NOT implemented. Spec 008 owns the assignment
 * entity — submission, late policy, grading, the next-session gate — so 016
 * reserves the slot rather than building half of it. The Action rejects it by
 * name, and AssignmentReservedTest fails the build if that stops being true.
 * Same shape as the notification channels in 003: a known value with no
 * implementation behind it is honest; a value that half works is not.
 */
enum LessonType: string
{
    case Video = 'video';
    case Audio = 'audio';
    case Pdf = 'pdf';
    case File = 'file';
    case Article = 'article';
    case Note = 'note';
    case Link = 'link';
    case Exam = 'exam';
    case Assignment = 'assignment';
    case LiveSession = 'live_session';

    public function label(): string
    {
        return match ($this) {
            self::Video => 'فيديو',
            self::Audio => 'صوت',
            self::Pdf => 'مستند PDF',
            self::File => 'ملف',
            self::Article => 'مقالة',
            self::Note => 'تنويه',
            self::Link => 'رابط خارجي',
            self::Exam => 'اختبار',
            self::Assignment => 'واجب',
            self::LiveSession => 'حصة مباشرة',
        };
    }
}
