<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\PlaybackGrant;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Actions\Action;
use DomainException;

/**
 * The mint: everything a playback grant IS, and nothing about who may have one.
 *
 * Extracted from {@see IssuePlaybackGrant} for spec 011, which needs a second
 * door — a book bought from the store is entitled by a `store_orders` row and by
 * nothing else, and `IssuePlaybackGrant::handle()` takes a `Lesson` and refuses
 * any asset whose `owner_type` is not `Lesson::class`. So a store product could
 * not ride it, and the alternative to extracting was a second `PlaybackGrant`
 * insert somewhere else in the tree.
 *
 * ⚠️ AND A SECOND INSERT IS THE WHOLE DANGER. The grant row is the only thing
 * standing between a paying student and a permanent link to the file: its ttl,
 * its binding to the `auth_session` (which is how the device limit reaches
 * playback — end the session and every grant it minted dies with it), and the
 * renewal loop the watermark component drives. Written twice, the two spellings
 * disagree at the first tuned setting, and the one that disagrees quietly is the
 * one nobody is watching.
 *
 * ⚠️ THE READINESS CHECK LIVES HERE, NOT AT EITHER DOOR. It is not an
 * entitlement branch — it asks about the FILE, and the answer is the same
 * whichever door was walked through. A door that forgot it would hand out a
 * playing grant for a video still transcoding, and the student would read an
 * error from the player instead of «قيد التجهيز».
 *
 * What this class must NEVER learn: an enrolment, a seat, a balance, an order.
 * Every one of those is a door's question, and a condition that creeps in here
 * is a condition the OTHER door starts enforcing without anyone deciding it
 * should.
 */
class MintPlaybackGrant extends Action
{
    /**
     * @throws DomainException when the asset exists but is not playable yet
     */
    public function handle(
        MediaAsset $asset,
        User $viewer,
        AuthSession $session,
        ?string $ipHash = null,
    ): PlaybackGrant {
        if (! $asset->isPlayable()) {
            // Distinct from "not allowed": the viewer is entitled, the video is
            // simply not ready. The screen says "قيد التجهيز" instead of an error.
            throw new DomainException($asset->status->value);
        }

        $ttl = (int) PlatformSettings::get('media.grant_ttl_seconds', 300);

        return PlaybackGrant::query()->create([
            'workspace_id' => $asset->workspace_id,
            'media_asset_id' => $asset->getKey(),
            'user_id' => $viewer->getKey(),
            // The binding that makes a copied link useless: when this session
            // ends, every grant it minted dies with it.
            'auth_session_id' => $session->getKey(),
            'expires_at' => now()->addSeconds($ttl),
            'issued_ip_hash' => $ipHash,
            'created_at' => now(),
        ]);
    }
}
