<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Media\Enums\MediaKind;
use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * What each kind of file is allowed to be.
 *
 * One place, because the same three questions are asked twice: once at ticket
 * time against what the client declared, and once at completion against the file
 * that actually arrived. The first is a courtesy — it saves a teacher watching a
 * gigabyte transfer that was always going to fail — and the second is the check
 * that counts, because a declared size is a number the client chooses.
 *
 * They were not the same numbers before 016: completion checked the 2 GiB video
 * ceiling whatever had been uploaded, so a 60 MB "PDF" passed the only check
 * that was real.
 *
 * Numbers come from `platform_settings` first and `config/media.php` second — a
 * limit that can only change by shipping code is a limit nobody ever tunes.
 */
final class MediaLimits
{
    public static function maxSizeBytes(MediaKind $kind): int
    {
        return match ($kind) {
            MediaKind::Video => (int) PlatformSettings::get('media.max_size_bytes'),
            MediaKind::Audio => (int) PlatformSettings::get('media.max_audio_size_bytes'),
            MediaKind::Document => (int) PlatformSettings::get('media.max_document_size_bytes'),
        };
    }

    /** Null where the kind carries no duration. */
    public static function maxDurationSeconds(MediaKind $kind): ?int
    {
        return match ($kind) {
            MediaKind::Video => (int) PlatformSettings::get('media.max_duration_seconds'),
            MediaKind::Audio => (int) PlatformSettings::get('media.max_audio_duration_seconds'),
            // A PDF has no duration to read.
            MediaKind::Document => null,
        };
    }

    /** @return list<string> */
    public static function allowedMimeTypes(MediaKind $kind): array
    {
        /** @var list<string> $types */
        $types = config('media.allowed_mime_types.'.$kind->value, []);

        return $types;
    }

    /**
     * The refusal names the actual number.
     *
     * "حجم الملف يتجاوز الحد المسموح" tells a teacher their file is too big and
     * nothing about what would fit, so the next attempt is another guess.
     */
    public static function sizeRefusal(MediaKind $kind): string
    {
        return sprintf(
            'حجم الملف يتجاوز الحد المسموح لهذا النوع (%s كحدّ أقصى).',
            self::humanBytes(self::maxSizeBytes($kind)),
        );
    }

    public static function durationRefusal(MediaKind $kind): string
    {
        $max = self::maxDurationSeconds($kind) ?? 0;

        return sprintf('مدة الملف تتجاوز الحد المسموح (%d دقيقة كحدّ أقصى).', intdiv($max, 60));
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1_073_741_824) {
            return rtrim(rtrim(number_format($bytes / 1_073_741_824, 1), '0'), '.').' غيغابايت';
        }

        return rtrim(rtrim(number_format($bytes / 1_048_576, 1), '0'), '.').' ميغابايت';
    }

    /**
     * The floor under a departed teacher's recordings (013 · FR-036).
     *
     * ⚠️ A FLOOR, NOT THE ANSWER. The retention is derived from when the last
     * person who paid for a seat loses their access; this is what applies when
     * that produces no date — an ordinary workspace where enrolments are
     * open-ended, which is the default shape here. It matches the catalogue's own
     * `class_recording` retention so a teacher leaving changes nothing for a
     * student whose access has no end date, which is the promise every other
     * student on the platform already has.
     */
    public static function departedTeacherFloorDays(): int
    {
        return (int) PlatformSettings::get('media.departed_teacher_retain_days', 730);
    }
}
