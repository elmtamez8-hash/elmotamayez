<?php

declare(strict_types=1);

namespace App\Modules\Media\Data;

use App\Modules\Media\Enums\MediaKind;
use App\Shared\Data\DataTransferObject;

/**
 * What this provider can actually do.
 *
 * This class is the answer to the awkward question in this phase: how do you
 * honour a requirement that needs a provider you have not chosen yet? By
 * declaring the capability instead of assuming it. The UI hides the quality
 * control when adaptiveBitrate is false, and the contract test forces any
 * implementation that claims a capability to deliver it.
 */
final class ProviderCapabilities extends DataTransferObject
{
    public function __construct(
        public readonly bool $adaptiveBitrate,
        public readonly bool $signedUrls,
        public readonly bool $automaticCaptions,
        public readonly bool $directUpload,
        public readonly int $maxSizeBytes,
        public readonly int $maxDurationSeconds,
        /*
         * Can this provider take a file over from a URL?
         *
         * ⚠️ "CAN IT", NOT "DOES IT WITHOUT BANDWIDTH". LocalMediaProvider
         * declares this true and implements it by downloading, which is what it
         * has always done. Declaring it false there would be honest about the
         * bandwidth and wrong about the capability — and it would force every
         * caller into two branches for one question, which is precisely what the
         * abstraction exists to prevent. Measuring the bandwidth is SC-001's job,
         * not this flag's (019 contracts §1).
         */
        public readonly bool $remoteFetch = false,
        /**
         * Which kinds this provider accepts, or null for every kind.
         *
         * ⚠️ THIS EXISTS BECAUSE A PDF WAS BEING SENT TO A VIDEO LIBRARY. Every
         * upload went through whichever provider `media.provider` named, with no
         * branch on kind — so the day production flipped to a commercial video
         * host, a teacher's worksheet had a *video object* created for it, under
         * video-sized ceilings, in a library that stores nothing else. It failed,
         * and it failed while naming the wrong cause.
         *
         * Declared rather than inferred, for the same reason as every flag above:
         * the alternative is a caller that knows which provider is which, and that
         * is the knowledge this whole interface exists to remove. `null` is "all",
         * so a provider that genuinely takes anything — our own disk — says nothing
         * and keeps saying nothing when a kind is added.
         *
         * @var list<MediaKind>|null
         */
        public readonly ?array $kinds = null,
    ) {}

    /** Whether this provider will take a file of this kind at all. */
    public function accepts(MediaKind $kind): bool
    {
        return $this->kinds === null || in_array($kind, $this->kinds, true);
    }
}
