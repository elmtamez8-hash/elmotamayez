<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Support\CountedNoun;

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

    /**
     * A chat attachment is smaller than a lesson file, and deliberately so.
     *
     * ⚠️ SEPARATE NUMBERS RATHER THAN THE KIND'S OWN. A voice note travels under
     * `MediaKind::Audio`, whose ceiling is 200 MiB and an hour — sized for a
     * recorded lecture. Applying it to a chat would let one message hold a
     * feature film's worth of audio, and the refusal a student eventually saw
     * would name the lecture limit. A picture likewise arrives as
     * `MediaKind::Document`, whose 50 MiB ceiling is sized for a slide deck.
     *
     * Read from `platform_settings` first, exactly like every other ceiling here:
     * a limit that can only change by shipping code is a limit nobody ever tunes.
     */
    public static function maxChatAttachmentBytes(): int
    {
        return (int) PlatformSettings::get('media.max_chat_attachment_bytes', 10_485_760);
    }

    /**
     * The most bytes the local upload route will accept for this asset.
     *
     * The receiving end needs its own ceiling because every check before it is
     * a number the client chose (the declared size) and every check after it
     * runs once the file is already on our disk. A lesson file or a store
     * product's file gets its kind's allowance (`media.full_allowance_owners`);
     * anything else that reaches this route is a chat attachment and gets the
     * smaller chat allowance, for the reason that method gives.
     *
     * ⚠️ IT USED TO BE «LESSON, OR ELSE CHAT», which made a third writer
     * (`RequestStoreFile`, 2026-10-01) a chat attachment by default: a 20 MB book
     * was promised 50 MB at the ticket and refused at 10 MB here.
     */
    public static function uploadCeilingFor(MediaAsset $asset): int
    {
        /** @var list<string> $full */
        $full = config('media.full_allowance_owners', [Lesson::class]);

        return in_array((string) $asset->owner_type, $full, true)
            ? self::maxSizeBytes($asset->kind)
            : min(self::maxSizeBytes($asset->kind), self::maxChatAttachmentBytes());
    }

    public static function maxVoiceNoteSeconds(): int
    {
        return (int) PlatformSettings::get('media.max_voice_note_seconds', 300);
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
     * What a chat attachment may be, per the kind it travels under.
     *
     * ⚠️ NOT THE LESSON LIST. A chat picture travels as `MediaKind::Document`,
     * whose list is PDFs and slide decks with two image types beside them — so a
     * PDF «picture» passed completion and rendered as a broken `<img>`, while a
     * WebP photograph (which the composer offers) was refused. And a voice note
     * is what a browser's recorder writes, `audio/webm` or `audio/mp4`, which a
     * list written for lecture recordings never named.
     *
     * The caller passes this into `CompleteMediaUpload`; Media itself never asks
     * what a conversation is.
     *
     * @return list<string>
     */
    public static function chatAllowedMimeTypes(MediaKind $kind): array
    {
        /** @var list<string> $types */
        $types = config('media.chat_allowed_mime_types.'.$kind->value, []);

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

        return 'مدة الملف تتجاوز الحد المسموح ('.CountedNoun::of(intdiv($max, 60), self::MINUTES).' كحدّ أقصى).';
    }

    /**
     * «دقيقة واحدة» · «دقيقتان» · «٥ دقائق» · «١٨٠ دقيقة» — the two refusals that
     * name a duration ceiling read one set of forms. «(%d دقيقة)» was the
     * `many` band for every value, and «(%d دقائق)» beside it the `few` band.
     *
     * @var array{one: string, two: string, few: string, many: string, other: string}
     */
    public const MINUTES = [
        'one' => 'دقيقة واحدة',
        'two' => 'دقيقتان',
        'few' => 'دقائق',
        'many' => 'دقيقة',
        'other' => 'دقيقة',
    ];

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
