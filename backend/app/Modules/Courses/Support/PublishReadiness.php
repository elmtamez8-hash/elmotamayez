<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

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
            // Presence, not readiness: an upload still transcoding IS content
            // the teacher has provided, and blocking on it would mean waiting
            // for a transcode to press publish.
            //
            // A failed one is not. `CompleteMediaUpload` deletes the bytes and
            // keeps the row so the teacher can see why it broke — and the row
            // alone used to satisfy this check, publishing a document item over
            // a file that does not exist.
            'asset' => self::hasUsablePrimaryAsset($lesson),
            default => true,
        };
    }

    /**
     * Any primary asset row, whatever its state.
     *
     * This is the predicate `ChangeLessonType` uses, and it deliberately counts
     * a FAILED upload: the row still points at a decision that belongs to the
     * two-factor delete route, so retyping the item around it would be the back
     * door that route exists to close.
     */
    public static function hasPrimaryAsset(Lesson $lesson): bool
    {
        return self::primaryAssets($lesson)->exists();
    }

    /** A primary asset that is, or will become, a real file. */
    private static function hasUsablePrimaryAsset(Lesson $lesson): bool
    {
        return self::primaryAssets($lesson)
            ->where('status', '!=', MediaAssetStatus::Failed)
            ->exists();
    }

    /** @return Builder<MediaAsset> */
    private static function primaryAssets(Lesson $lesson): Builder
    {
        return MediaAsset::query()
            ->where('owner_type', Lesson::class)
            ->where('owner_id', $lesson->getKey())
            ->where('role', MediaRole::Primary);
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
