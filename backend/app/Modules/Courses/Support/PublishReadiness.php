<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use DomainException;

/**
 * What an item still needs before it may be published.
 *
 * **Checked at publish, never at save.** A draft is a half-written thing by
 * definition — refusing to save an article with no body yet would mean the
 * teacher cannot leave and come back, which is the one thing drafts exist for.
 * The requirement bites at the moment the item becomes something a student can
 * open, and not a moment earlier (FR-022).
 *
 * The field list is not repeated here: it comes from `LessonTypeRegistry`, the
 * same map the progress denominator and the editor read. A second copy in a
 * FormRequest is how "an article needs a body" starts meaning two things.
 */
final class PublishReadiness
{
    /**
     * The fields this item is missing, named in Arabic for the refusal message.
     *
     * @return list<string>
     */
    public static function missingFields(Lesson $lesson): array
    {
        $type = LessonType::from($lesson->type);
        $missing = [];

        foreach (LessonTypeRegistry::requiredToPublish($type) as $field) {
            if (! self::satisfies($lesson, $field)) {
                $missing[] = self::label($field, $type);
            }
        }

        return $missing;
    }

    public static function assertPublishable(Lesson $lesson): void
    {
        $missing = self::missingFields($lesson);

        if ($missing === []) {
            return;
        }

        // The item is named as well as the field. A publish is a batch, so
        // "المحتوى مطلوب" alone leaves the teacher hunting for which of eleven
        // items it belongs to.
        throw new DomainException(sprintf(
            'لا يمكن نشر «%s» قبل استكماله: %s.',
            $lesson->title,
            implode(' · ', $missing),
        ));
    }

    private static function satisfies(Lesson $lesson, string $field): bool
    {
        return match ($field) {
            'content' => trim((string) $lesson->content) !== '',
            'external_url' => trim((string) $lesson->external_url) !== '',
            'reference_id' => $lesson->reference_id !== null,
            // Presence, not readiness: an upload still transcoding is content
            // the teacher has provided. A failed one is not, and that is the
            // asset's own status telling the truth on the lesson page.
            'asset' => self::hasPrimaryAsset($lesson),
            default => true,
        };
    }

    public static function hasPrimaryAsset(Lesson $lesson): bool
    {
        return MediaAsset::query()
            ->where('owner_type', Lesson::class)
            ->where('owner_id', $lesson->getKey())
            ->where('role', MediaRole::Primary)
            ->exists();
    }

    private static function label(string $field, LessonType $type): string
    {
        return match ($field) {
            'content' => 'النصّ مطلوب',
            'external_url' => 'الرابط مطلوب',
            'reference_id' => $type === LessonType::Exam
                ? 'اختر الاختبار الذي يضعه هذا العنصر'
                : 'اختر الحصة التي يضعها هذا العنصر',
            'asset' => sprintf('ارفع الملف (%s)', LessonTypeRegistry::assetKind($type)?->label() ?? 'ملف'),
            default => $field,
        };
    }
}
