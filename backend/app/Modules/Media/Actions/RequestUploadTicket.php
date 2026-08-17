<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Exceptions\ContentLockedException;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Media\Data\UploadTicket;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaLimits;
use App\Modules\Media\Support\MediaProviderResolver;
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
        /*
         * ⚠️ THE RESOLVER, NOT THE BOUND PROVIDER — AND THE REASON IS A PDF.
         *
         * This used to inject `MediaProviderInterface` and hand every kind to
         * whichever provider `media.provider` named. Flipping that switch to a
         * commercial video host therefore had a *video object* created for every
         * worksheet a teacher uploaded, under video-sized ceilings, in a library
         * that stores nothing else — failing while naming the wrong cause.
         *
         * `forKind()` asks the provider what it accepts and sends the rest to our
         * own disk. The Action still names nobody: which provider takes what is a
         * declaration on the provider, read by the resolver.
         */
        private readonly MediaProviderResolver $providers,
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

        if ($role === MediaRole::Primary && $lesson->mediaAsset !== null) {
            // **Refused, not replaced.** This used to delete the existing primary
            // asset here — `provider->delete()` then `->delete()` — which was a
            // second door onto a decision deliberately fenced since 004:
            //
            // - no second factor, on a route carrying only `auth:sanctum`, so the
            //   2FA on `DELETE /media/assets/{asset}` was optional in practice;
            // - not even `LESSONS_DELETE`, so an assistant whom the policy
            //   forbids to delete an asset could destroy a 2 GB lecture by
            //   posting a one-byte upload ticket;
            // - and outside `DeleteMediaAsset`, so live grants were never revoked
            //   and `media_captions` rows were orphaned with their WebVTT files
            //   still on disk (neither table has a foreign key).
            //
            // Three documents asserted the opposite — contracts §2, FR-038أ, and
            // `ManageLessons`' own comment calling `DeleteMediaAsset` "the only
            // path that takes the bytes down at the provider as well as the row".
            // The cheap fix was to reword them again. The correct one is to make
            // the sentence true: replacing a file is deleting a file, and it goes
            // through the guarded route like any other deletion.
            // `delete_asset`, not the default `archive`: what unblocks this is
            // removing the file, and a refusal that names the wrong way out is a
            // refusal the teacher cannot act on.
            throw new ContentLockedException(
                'هذا العنصر يحمل ملفاً بالفعل. احذف الملف الحالي أولاً — حذفه يتطلّب تحقّقاً بخطوتين — ثم ارفع البديل.',
                alternative: 'delete_asset',
            );
        }

        $provider = $this->providers->forKind($kind);

        $asset = new MediaAsset([
            'workspace_id' => $lesson->workspace_id,
            'owner_type' => Lesson::class,
            'owner_id' => $lesson->getKey(),
            // Stamped from whoever actually took it, which is what lets
            // `MediaProviderResolver::for()` find it again later. A column that
            // recorded the CONFIGURED provider instead would be a lie the day a
            // kind was routed elsewhere.
            'provider' => $provider->identifier(),
            'kind' => $kind,
            'role' => $role,
            // Stated for the same reason as the two above, and it was the one
            // left out: a model built with `new` carries no column default, and
            // because this key IS in `casts()` the accessor returned null rather
            // than falling back to false. The ticket response said
            // `is_downloadable: null` where every later read said `false`.
            'is_downloadable' => false,
            'status' => MediaAssetStatus::Pending,
            'original_filename' => $originalFilename,
            'size_bytes' => $declaredSizeBytes,
            'duration_seconds' => $declaredDurationSeconds,
        ]);
        $asset->save();

        return [
            'asset' => $asset,
            'ticket' => $provider->createUploadTicket($asset),
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
