<?php

declare(strict_types=1);

namespace App\Modules\Media\Data;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\PlaybackGrant;
use App\Shared\Data\DataTransferObject;
use Carbon\CarbonImmutable;
use DomainException;
use InvalidArgumentException;

/**
 * Everything a provider needs to mint a manifest for one viewer, once.
 *
 * Two shapes. A STUDENT plays through a saved `PlaybackGrant` (tied to their
 * account and sign-in session). A GUEST watching a course's «حصة تجريبية»
 * (spec 040) has no grant at all: only an expiry, because nothing is recorded
 * against anybody. So `grant` is null exactly when `expiresAt` is given, and a
 * provider that needs a grant row (the local one builds its stream URL from
 * the grant's uuid) asks `requireGrant()` and refuses loudly instead of
 * building `/playback//stream` from a null.
 */
final class PlaybackContext extends DataTransferObject
{
    public function __construct(
        public readonly MediaAsset $asset,
        public readonly ?PlaybackGrant $grant = null,
        public readonly ?string $viewerIpHash = null,
        public readonly ?CarbonImmutable $expiresAt = null,
    ) {
        if (($grant === null) === ($expiresAt === null)) {
            throw new InvalidArgumentException('A playback context carries a grant or a guest expiry, exactly one.');
        }
    }

    /** When the signed manifest stops working. */
    public function expiresAt(): CarbonImmutable
    {
        return $this->expiresAt
            ?? CarbonImmutable::instance($this->requireGrant()->expires_at->toDateTimeImmutable());
    }

    public function requireGrant(): PlaybackGrant
    {
        return $this->grant ?? throw new DomainException('This provider cannot serve a guest without a grant.');
    }
}
