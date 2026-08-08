<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Data\UploadTicket;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaLimits;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Reserves an asset row and returns somewhere to upload to.
 *
 * The declared size and duration are checked before a single byte moves. It is a
 * claim the client can lie about, and the real check happens on completion
 * against the file itself — but rejecting an oversized upload up front saves the
 * teacher from watching a gigabyte transfer that was always going to fail.
 *
 * Two rules arrived with 016, both of which used to be impossible to state:
 *
 * **Replacement is for the primary only.** This Action deleted "the lesson's
 * asset" before creating the next one, which is right for the lesson's own file
 * and wrong the moment a worksheet is attached beside it. Attachments are many;
 * the primary is one.
 *
 * **A primary's kind must match the item's type.** Nothing stopped a video being
 * uploaded to a `pdf` item, and `PublishReadiness` only asks whether a primary
 * asset exists — so it published, and the student got a play button over a file
 * their browser downloads instead.
 */
class RequestUploadTicket extends Action
{
    public function __construct(
        private readonly MediaProviderInterface $provider,
    ) {}

    /** @return array{asset: MediaAsset, ticket: UploadTicket} */
    public function handle(
        Lesson $lesson,
        string $originalFilename,
        ?int $declaredSizeBytes = null,
        ?int $declaredDurationSeconds = null,
        MediaKind $kind = MediaKind::Video,
        MediaRole $role = MediaRole::Primary,
    ): array {
        if ($role === MediaRole::Primary) {
            $this->assertKindMatchesType($lesson, $kind);
        }

        $this->assertWithinLimits($kind, $declaredSizeBytes, $declaredDurationSeconds);

        if ($role === MediaRole::Primary) {
            // Re-uploading replaces the item's own file rather than leaving an
            // orphan the teacher cannot see or delete. Scoped to the primary by
            // the relation — an unscoped one would take out a worksheet.
            $existing = $lesson->mediaAsset;

            if ($existing !== null) {
                $this->provider->delete($existing);
                $existing->delete();
            }
        }

        $asset = new MediaAsset([
            'workspace_id' => $lesson->workspace_id,
            'owner_type' => Lesson::class,
            'owner_id' => $lesson->getKey(),
            'provider' => $this->provider->identifier(),
            'kind' => $kind,
            'role' => $role,
            'status' => MediaAssetStatus::Pending,
            'original_filename' => $originalFilename,
            'size_bytes' => $declaredSizeBytes,
            'duration_seconds' => $declaredDurationSeconds,
        ]);
        $asset->save();

        return [
            'asset' => $asset,
            'ticket' => $this->provider->createUploadTicket($asset),
        ];
    }

    /**
     * The item's type decides what its own file may be.
     *
     * Attachments are deliberately exempt: FR-019 puts a worksheet under a video
     * and slides under an article, so constraining them would be constraining
     * the feature.
     */
    private function assertKindMatchesType(Lesson $lesson, MediaKind $kind): void
    {
        $expected = LessonTypeRegistry::assetKind(LessonType::from($lesson->type));

        if ($expected === null) {
            throw new DomainException(
                'هذا النوع من العناصر لا يحمل ملفاً خاصاً به. أرفق الملف كمرفق، أو غيّر نوع العنصر.',
            );
        }

        if ($expected !== $kind) {
            throw new DomainException(sprintf(
                'هذا العنصر من نوع «%s»، فملفه يجب أن يكون %s لا %s. غيّر نوع العنصر أو أرفق الملف كمرفق.',
                LessonType::from($lesson->type)->label(),
                $expected->label(),
                $kind->label(),
            ));
        }
    }

    private function assertWithinLimits(MediaKind $kind, ?int $sizeBytes, ?int $durationSeconds): void
    {
        if ($sizeBytes !== null && $sizeBytes > MediaLimits::maxSizeBytes($kind)) {
            throw new DomainException(MediaLimits::sizeRefusal($kind));
        }

        $maxDuration = MediaLimits::maxDurationSeconds($kind);

        if ($durationSeconds !== null && $maxDuration !== null && $durationSeconds > $maxDuration) {
            throw new DomainException(MediaLimits::durationRefusal($kind));
        }
    }
}
